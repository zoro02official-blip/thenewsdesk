<?php
// ═══════════════════════════════════════════════════════════
//  The News Desk — Articles API
//
//  GET    /api/articles.php              → published articles only
//  GET    /api/articles.php?id=X         → single article
//  GET    /api/articles.php?admin=1      → all articles incl. drafts (auth required)
//  POST   /api/articles.php              → create (auth required)
//  PUT    /api/articles.php              → update (auth required, partial-safe)
//  DELETE /api/articles.php?id=X         → delete (auth required)
//  POST   /api/articles.php?action=view&id=X → increment view count
// ═══════════════════════════════════════════════════════════

session_start();
require_once '../config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── View count increment (public, no auth) ────────────────
if ($method === 'POST' && $action === 'view' && $id > 0) {
    $db->prepare('UPDATE articles SET views = views + 1 WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── GET ───────────────────────────────────────────────────
if ($method === 'GET') {
    $isAdmin = !empty($_GET['admin']) && !empty($_SESSION['user']);

    if ($id > 0) {
        // Single article — always return regardless of published status
        $stmt = $db->prepare('SELECT * FROM articles WHERE id = ?');
        $stmt->execute([$id]);
        $article = $stmt->fetch();
        if (!$article) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        echo json_encode(formatArticle($article));
        exit;
    }

    // All articles — always merge demo articles (ID 9001–9008) with DB articles
    if ($isAdmin) {
        // Admin: ALL DB articles (incl. drafts) + demo articles
        $stmt = $db->query('SELECT * FROM articles ORDER BY date DESC');
        $rows = $stmt->fetchAll();
        $result = array_merge(getDemoArticles(), array_map('formatArticle', $rows));
    } else {
        // Public: published DB articles + demo articles
        $stmt = $db->query('SELECT * FROM articles WHERE published = 1 ORDER BY date DESC');
        $rows = $stmt->fetchAll();
        $result = array_merge(getDemoArticles(), array_map('formatArticle', $rows));
    }
    // Sort merged list by date descending
    usort($result, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    echo json_encode(array_values($result));
    exit;
}

// ── POST (create) ─────────────────────────────────────────
if ($method === 'POST') {
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $title = trim($body['title'] ?? '');
    $cat   = trim($body['cat']   ?? '');
    if (!$title || !$cat) {
        http_response_code(400);
        echo json_encode(['error' => 'Title and category are required']);
        exit;
    }

    // Safe date parsing — never pass empty/false to date()
    $dateRaw = $body['date'] ?? '';
    if (!empty($dateRaw) && ($ts = strtotime($dateRaw)) !== false) {
        $dateVal = date('Y-m-d H:i:s', $ts);
    } else {
        $dateVal = date('Y-m-d H:i:s');
    }

    $stmt = $db->prepare(
        'INSERT INTO articles (title, excerpt, content, category, author, image, tags, published, featured, breaking, date)
         VALUES (:title, :exc, :body, :cat, :author, :img, :tags, :pub, :feat, :brk, :date)'
    );
    $stmt->execute([
        'title'  => $title,
        'exc'    => trim($body['exc']    ?? ''),
        'body'   => trim($body['body']   ?? ''),
        'cat'    => $cat,
        'author' => trim($body['author'] ?? 'Staff Reporter') ?: 'Staff Reporter',
        'img'    => trim($body['img']    ?? ''),
        'tags'   => is_array($body['tags'] ?? null) ? implode(',', $body['tags']) : trim($body['tags'] ?? ''),
        'pub'    => isset($body['pub']) ? (int)(bool)$body['pub'] : (isset($body['published']) ? (int)(bool)$body['published'] : 1),
        'feat'   => (int)(bool)($body['feat']    ?? $body['featured'] ?? false),
        'brk'    => (int)(bool)($body['brk']     ?? $body['breaking'] ?? false),
        'date'   => $dateVal,
    ]);

    $newId   = (int)$db->lastInsertId();
    $fetchSt = $db->prepare('SELECT * FROM articles WHERE id = ?');
    $fetchSt->execute([$newId]);
    echo json_encode(['success' => true, 'article' => formatArticle($fetchSt->fetch())]);
    exit;
}

// ── PUT (update) — partial-safe: missing fields keep existing values ──
if ($method === 'PUT') {
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Article ID required']); exit; }

    // Fetch existing row so partial updates (e.g. togglePublish) don't wipe fields
    $check = $db->prepare('SELECT * FROM articles WHERE id = ?');
    $check->execute([$id]);
    $existing = $check->fetch();
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }

    // Safe date parsing
    $dateRaw = $body['date'] ?? '';
    if (!empty($dateRaw) && ($ts = strtotime($dateRaw)) !== false) {
        $dateVal = date('Y-m-d H:i:s', $ts);
    } else {
        // Keep existing date if none provided
        $dateVal = $existing['date'];
    }

    $stmt = $db->prepare(
        'UPDATE articles SET
            title     = :title,
            excerpt   = :exc,
            content   = :body,
            category  = :cat,
            author    = :author,
            image     = :img,
            tags      = :tags,
            published = :pub,
            featured  = :feat,
            breaking  = :brk,
            date      = :date
         WHERE id = :id'
    );
    $stmt->execute([
        // Fall back to existing value for any field not supplied in the payload
        'title'  => isset($body['title'])  ? trim($body['title'])  : $existing['title'],
        'exc'    => isset($body['exc'])    ? trim($body['exc'])    : $existing['excerpt'],
        'body'   => isset($body['body'])   ? trim($body['body'])   : $existing['content'],
        'cat'    => isset($body['cat'])    ? trim($body['cat'])    : $existing['category'],
        'author' => isset($body['author']) ? (trim($body['author']) ?: 'Staff Reporter') : $existing['author'],
        'img'    => isset($body['img'])    ? trim($body['img'])    : $existing['image'],
        'tags'   => isset($body['tags'])
                        ? (is_array($body['tags']) ? implode(',', $body['tags']) : trim($body['tags']))
                        : $existing['tags'],
        'pub'    => isset($body['pub'])
                        ? (int)(bool)$body['pub']
                        : (isset($body['published']) ? (int)(bool)$body['published'] : (int)$existing['published']),
        'feat'   => isset($body['feat'])
                        ? (int)(bool)$body['feat']
                        : (isset($body['featured']) ? (int)(bool)$body['featured'] : (int)$existing['featured']),
        'brk'    => isset($body['brk'])
                        ? (int)(bool)$body['brk']
                        : (isset($body['breaking']) ? (int)(bool)$body['breaking'] : (int)$existing['breaking']),
        'date'   => $dateVal,
        'id'     => $id,
    ]);

    $fetchSt = $db->prepare('SELECT * FROM articles WHERE id = ?');
    $fetchSt->execute([$id]);
    echo json_encode(['success' => true, 'article' => formatArticle($fetchSt->fetch())]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────
if ($method === 'DELETE') {
    requireAuth();
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Article ID required']); exit; }

    $check = $db->prepare('SELECT id FROM articles WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }

    $db->prepare('DELETE FROM articles WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true, 'deleted' => $id]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

// ── Helper: demo articles served when DB has no published articles ─────
function getDemoArticles(): array {
    $now = date('Y-m-d H:i:s');
    $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
    $twoDays   = date('Y-m-d H:i:s', strtotime('-2 days'));
    $threeDays = date('Y-m-d H:i:s', strtotime('-3 days'));
    return [
        [
            'id' => 9001, 'published' => true, 'featured' => true, 'breaking' => false, 'views' => 1240,
            'title'  => 'Federal Reserve Signals Pause on Rate Hikes Amid Cooling Inflation',
            'exc'    => 'Policymakers cite easing price pressures and a resilient labour market as reasons to hold rates steady at the upcoming June meeting.',
            'body'   => '<p>Federal Reserve officials have signalled they are prepared to hold interest rates steady at their next meeting, as inflation data continues to trend downward and the broader economy remains resilient. Speaking at a conference on monetary policy, Fed Chair Jerome Powell noted that "policy is in a good place" and that future adjustments would depend heavily on incoming data.</p><p>The core Personal Consumption Expenditures (PCE) index, the Fed\'s preferred inflation gauge, fell to 2.7% year-over-year in the most recent reading, its lowest level since early 2021. Meanwhile, the unemployment rate held at 3.9%, well below historical recession thresholds.</p><p>Traders in federal funds futures markets now assign a greater than 70% probability to rates remaining unchanged through the summer, a sharp reversal from expectations three months ago that priced in multiple cuts by mid-year.</p>',
            'cat'    => 'Business', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?w=1200&q=80',
            'tags'   => ['Finance', 'Federal Reserve', 'Economy'],
            'date'   => $now,
        ],
        [
            'id' => 9002, 'published' => true, 'featured' => false, 'breaking' => true, 'views' => 892,
            'title'  => 'SpaceX Starship Completes First Fully Successful Orbital Test Flight',
            'exc'    => 'The 400-foot rocket completed a full orbital loop and splashed down precisely on target, marking a watershed moment for deep-space exploration.',
            'body'   => '<p>SpaceX\'s Starship vehicle completed its most ambitious test flight to date, achieving a full orbital trajectory and executing a controlled splashdown in the Indian Ocean. The milestone marks the first time the fully integrated Super Heavy booster and Starship upper stage have completed the entire flight profile as designed.</p><p>CEO Elon Musk called the achievement "a giant leap for life becoming multi-planetary," while NASA administrators noted the significance for the Artemis lunar programme, which relies on Starship as its crewed lunar lander.</p>',
            'cat'    => 'Technology', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1517976487492-5750f3195933?w=800&q=80',
            'tags'   => ['Space', 'SpaceX', 'Technology'],
            'date'   => $yesterday,
        ],
        [
            'id' => 9003, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 674,
            'title'  => 'G7 Leaders Agree on New Framework for Taxing Multinational Tech Giants',
            'exc'    => 'A landmark accord would impose a 15% global minimum corporate tax, redirecting billions in revenue to smaller economies.',
            'body'   => '<p>Leaders of the Group of Seven nations reached a preliminary agreement on a framework to impose a 15% global minimum corporate tax on the world\'s largest multinational companies, with a particular focus on technology firms that have historically parked profits in low-tax jurisdictions.</p><p>The deal, if ratified by member legislatures, could redirect an estimated $150 billion annually to governments, funds that proponents argue would help rebuild public infrastructure and fund social programmes strained by years of underinvestment.</p>',
            'cat'    => 'Politics', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1529107386315-e1a2ed48a620?w=800&q=80',
            'tags'   => ['G7', 'Tax', 'Politics'],
            'date'   => $yesterday,
        ],
        [
            'id' => 9004, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 541,
            'title'  => 'AI Outperforms Doctors in Detecting Early-Stage Pancreatic Cancer',
            'exc'    => 'A deep-learning model trained on 100,000 CT scans identified tumours up to 18 months earlier than conventional screening, researchers say.',
            'body'   => '<p>A new artificial intelligence system developed by researchers at MIT and the Dana-Farber Cancer Institute has demonstrated the ability to detect early-stage pancreatic cancer from routine CT scans with significantly greater accuracy than experienced radiologists, according to a study published in Nature Medicine.</p><p>The model, trained on over 100,000 annotated scans, identified malignant lesions at a stage where surgical resection is still possible in 87% of cases, compared to 46% for the current standard of care. Pancreatic cancer carries a five-year survival rate of under 12% largely because it is typically diagnosed at an advanced stage.</p>',
            'cat'    => 'Science', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1576091160550-2173dba999ef?w=800&q=80',
            'tags'   => ['Health', 'AI', 'Cancer Research'],
            'date'   => $twoDays,
        ],
        [
            'id' => 9005, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 489,
            'title'  => 'Ukraine Secures Record €50 Billion Reconstruction Loan From European Partners',
            'exc'    => 'The package, the largest single disbursement since the conflict began, will fund infrastructure rebuilding and energy resilience.',
            'body'   => '<p>Ukraine has secured a €50 billion reconstruction loan from a consortium of European governments and international financial institutions, the largest single funding package since Russia\'s full-scale invasion began in February 2022. The funds are earmarked for rebuilding critical infrastructure, including power generation, housing, and transport networks in regions liberated from occupation.</p>',
            'cat'    => 'World News', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1569144157591-c60f3f82f137?w=800&q=80',
            'tags'   => ['Ukraine', 'Europe', 'Finance'],
            'date'   => $twoDays,
        ],
        [
            'id' => 9006, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 378,
            'title'  => 'Apple Unveils Vision Pro 2 With 40% Weight Reduction and All-Day Battery',
            'exc'    => 'The second-generation spatial computing headset addresses the two most cited criticisms of its predecessor: bulk and endurance.',
            'body'   => '<p>Apple has unveiled the second generation of its Vision Pro spatial computing headset, addressing the two most common criticisms of the original device: weight and battery life. The new model is 40% lighter, achieves this through a redesigned optical stack and a new carbon-fibre frame, and features an integrated battery rated for 10 hours of continuous use — up from the roughly two hours the original offered.</p>',
            'cat'    => 'Technology', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1607016284318-d1a2d82c3b22?w=800&q=80',
            'tags'   => ['Apple', 'Technology', 'AR/VR'],
            'date'   => $threeDays,
        ],
        [
            'id' => 9007, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 312,
            'title'  => 'Global Carbon Emissions Fall for First Time Since Industrial Revolution',
            'exc'    => 'A surge in renewable energy deployment and electrification pushed 2024 emissions 2.3% below their 2023 peak, the IEA reports.',
            'body'   => '<p>Global carbon dioxide emissions declined in 2024 for the first time since the Industrial Revolution outside of a major economic contraction, the International Energy Agency reported, driven by an unprecedented surge in solar and wind power installation and accelerating electrification of transport and heating.</p><p>Total energy-related CO₂ emissions fell 2.3% year-over-year to 36.4 billion tonnes, a threshold the agency called "a potential turning point" in the trajectory of the climate crisis.</p>',
            'cat'    => 'Science', 'author' => 'Staff Reporter',
            'img'    => 'https://images.unsplash.com/photo-1473341304170-971dccb5ac1e?w=800&q=80',
            'tags'   => ['Climate', 'Environment', 'Energy'],
            'date'   => $threeDays,
        ],
        [
            'id' => 9008, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 265,
            'title'  => 'Hollywood Writers Strike Ends as Studios Accept AI Usage Guardrails',
            'exc'    => 'A three-year contract includes binding restrictions on how generative AI can be used in script development, a first for the industry.',
            'body'   => '<p>The Writers Guild of America and the Alliance of Motion Picture and Television Producers reached a new three-year contract that includes landmark provisions governing the use of generative artificial intelligence in script development. The agreement, ratified by an overwhelming majority of guild members, prohibits studios from using AI-generated material as the basis for a writer\'s assignment and bars AI-drafted content from counting toward minimum compensation thresholds.</p>',
            'cat'    => 'Entertainment', 'author' => 'Aisha Kamara',
            'img'    => 'https://images.unsplash.com/photo-1485846234645-a62644f84728?w=800&q=80',
            'tags'   => ['Hollywood', 'AI', 'Entertainment'],
            'date'   => $threeDays,
        ],
        [
            'id' => 9009, 'published' => true, 'featured' => true, 'breaking' => false, 'views' => 1853,
            'title'  => 'India Wins ICC Cricket World Cup in Last-Ball Thriller Against Australia',
            'exc'    => 'Rohit Sharma\'s unbeaten 94 guided India to a one-wicket victory in a match that will be remembered as one of the greatest finals in the tournament\'s history.',
            'body'   => '<p>India clinched the ICC Cricket World Cup for the third time in their history with a dramatic one-wicket victory over Australia in Ahmedabad, winning off the last ball of the final to send a nation of over a billion into celebrations that lasted through the night.</p><p>Captain Rohit Sharma anchored the chase with an unbeaten 94, marshalling the lower order through a nervy final ten overs to bring India home from 210 for 8 chasing 241. It was India\'s first World Cup title since 2011.</p>',
            'cat'    => 'Sports', 'author' => 'Marcus Thompson',
            'img'    => 'https://images.unsplash.com/photo-1540747913346-19212a4cf528?w=800&q=80',
            'tags'   => ['Cricket', 'ICC', 'India', 'Sports'],
            'date'   => $yesterday,
        ],
        [
            'id' => 9010, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 744,
            'title'  => 'Mediterranean Diet Linked to 25% Lower Risk of Dementia, Landmark Study Finds',
            'exc'    => 'Researchers tracked 60,000 adults over two decades and found that consistent adherence to olive oil, fish, and vegetable-rich eating patterns significantly protected cognitive function.',
            'body'   => '<p>A landmark 20-year longitudinal study involving more than 60,000 adults across eight countries has found that consistent adherence to a Mediterranean diet — rich in olive oil, fish, legumes, and vegetables — is associated with a 25% reduction in the risk of developing dementia, including Alzheimer\'s disease.</p><p>The research, published in the New England Journal of Medicine, is the largest study of its kind and provides the most robust evidence to date that dietary patterns in midlife have significant long-term consequences for brain health.</p>',
            'cat'    => 'Lifestyle', 'author' => 'Aisha Kamara',
            'img'    => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?w=800&q=80',
            'tags'   => ['Health', 'Diet', 'Dementia', 'Lifestyle'],
            'date'   => $twoDays,
        ],
        [
            'id' => 9011, 'published' => true, 'featured' => false, 'breaking' => true, 'views' => 2210,
            'title'  => 'China Launches First Crewed Mission to Mars, Beats NASA by Two Years',
            'exc'    => 'Three taikonauts are en route to the red planet aboard the Tianwen-3 spacecraft in a mission that marks a historic shift in the global space race.',
            'body'   => '<p>China has launched its first crewed mission to Mars, sending three taikonauts aboard the Tianwen-3 spacecraft in a mission that, if successful, will make China the first nation to place humans on another planet and represents a seismic shift in the global space race.</p><p>The launch from the Wenchang Space Launch Centre took place at 03:17 local time and was broadcast live by state television to an audience of hundreds of millions. The crew of two mission specialists and a flight engineer faces a seven-month transit to Mars before entering orbit.</p>',
            'cat'    => 'Science', 'author' => 'Elena Vasquez',
            'img'    => 'https://images.unsplash.com/photo-1614728894747-a83421e2b9c9?w=800&q=80',
            'tags'   => ['China', 'Mars', 'Space', 'Science'],
            'date'   => $now,
        ],
        [
            'id' => 9012, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 619,
            'title'  => 'OpenAI Releases GPT-5 With Reasoning Abilities That Rival PhD-Level Experts',
            'exc'    => 'The new model demonstrates breakthrough performance on scientific benchmarks and can generate novel mathematical proofs, according to independent evaluators.',
            'body'   => '<p>OpenAI has released GPT-5, a large language model that the company says demonstrates reasoning capabilities approaching those of PhD-level experts in mathematics, physics, and computer science, according to benchmarks run by independent academic evaluators.</p><p>The model scores in the 95th percentile on the Putnam Mathematical Competition, generates valid novel proofs of previously unproven conjectures in graph theory, and achieves near-perfect performance on the GPQA Diamond benchmark, which tests graduate-level knowledge in biology, chemistry, and physics.</p>',
            'cat'    => 'Technology', 'author' => 'Elena Vasquez',
            'img'    => 'https://images.unsplash.com/photo-1677442135703-1787eea5ce01?w=800&q=80',
            'tags'   => ['AI', 'OpenAI', 'GPT-5', 'Technology'],
            'date'   => $yesterday,
        ],
        [
            'id' => 9013, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 487,
            'title'  => 'BRICS Nations Announce New Reserve Currency to Reduce Dollar Dependence',
            'exc'    => 'The bloc unveiled a commodity-backed settlement unit for trade between member states, with China and Russia pushing for rapid adoption.',
            'body'   => '<p>The BRICS bloc of nations — Brazil, Russia, India, China, and South Africa, along with six newly admitted members — has announced the creation of a new reserve currency for trade settlement among member states, the most ambitious challenge to dollar dominance in decades.</p><p>The currency, provisionally named the BRICS Unit, will be partially backed by a basket of commodities including gold, oil, and rare earth minerals. While analysts caution that displacing the dollar in global trade remains a decades-long project, the announcement signals a deepening commitment from the bloc\'s largest members to reduce exposure to US financial sanctions.</p>',
            'cat'    => 'Business', 'author' => 'James Okafor',
            'img'    => 'https://images.unsplash.com/photo-1526304640581-d334cdbbf45e?w=800&q=80',
            'tags'   => ['BRICS', 'Economy', 'Dollar', 'Finance'],
            'date'   => $twoDays,
        ],
        [
            'id' => 9014, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 391,
            'title'  => 'Real Madrid Win Record 16th Champions League, Beating Manchester City 3–1',
            'exc'    => 'Vinicius Jr scored twice in the second half as Los Blancos ended the final at Wembley with a commanding victory over the Premier League champions.',
            'body'   => '<p>Real Madrid claimed a record-extending 16th UEFA Champions League title with a 3–1 victory over Manchester City in the final at Wembley Stadium, with Vinicius Jr. delivering a man-of-the-match performance to cement Los Blancos\'s status as the dominant force in European football.</p><p>Jude Bellingham opened the scoring from the penalty spot before Phil Foden equalised for City, but Vinicius Sr. restored Madrid\'s lead six minutes into the second half, and Bellingham sealed the win with a powerful header from a corner with seven minutes remaining.</p>',
            'cat'    => 'Sports', 'author' => 'Marcus Thompson',
            'img'    => 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=800&q=80',
            'tags'   => ['Champions League', 'Real Madrid', 'Football', 'Sports'],
            'date'   => $threeDays,
        ],
        [
            'id' => 9015, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 528,
            'title'  => 'South Korea Passes Four-Day Workweek Law, Joining Growing Global Movement',
            'exc'    => 'The legislation mandates a trial period for companies with over 300 employees, with a full rollout planned by 2027 pending economic review.',
            'body'   => '<p>South Korea\'s National Assembly has passed landmark legislation requiring companies with more than 300 employees to trial a four-day, 32-hour workweek, joining a growing number of countries experimenting with reduced working time amid evidence that productivity and worker wellbeing both improve under the model.</p><p>The law follows successful trials in Iceland, Japan, and the United Kingdom, where the overwhelming majority of participating companies chose to make the arrangement permanent after the pilot period ended.</p>',
            'cat'    => 'World News', 'author' => 'Priya Sharma',
            'img'    => 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80',
            'tags'   => ['Work', 'South Korea', 'Policy', 'Lifestyle'],
            'date'   => $twoDays,
        ],
        [
            'id' => 9016, 'published' => true, 'featured' => false, 'breaking' => false, 'views' => 334,
            'title'  => 'Ozempic Maker Novo Nordisk Becomes Europe\'s Most Valuable Company',
            'exc'    => 'Surging demand for GLP-1 weight-loss drugs has pushed the Danish pharmaceutical giant\'s market capitalisation past €600 billion.',
            'body'   => '<p>Novo Nordisk, the Danish pharmaceutical company behind the blockbuster weight-loss drug Ozempic and its sister product Wegovy, has surpassed LVMH and ASML to become the most valuable publicly traded company in Europe, with a market capitalisation exceeding €600 billion.</p><p>The milestone reflects extraordinary investor confidence in the GLP-1 drug class, which has demonstrated efficacy not only in weight management but in reducing cardiovascular events, kidney disease progression, and, in early trials, addiction-related behaviours. Analysts at Goldman Sachs estimate that GLP-1 drugs could become a $1 trillion market by 2030.</p>',
            'cat'    => 'Business', 'author' => 'James Okafor',
            'img'    => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=800&q=80',
            'tags'   => ['Pharma', 'Ozempic', 'Business', 'Health'],
            'date'   => $threeDays,
        ],
    ];
}

// ── Helper: normalize DB row to frontend format ───────────
function formatArticle(array $row): array {
    return [
        'id'        => (int)$row['id'],
        'title'     => $row['title']    ?? '',
        'exc'       => $row['excerpt']  ?? '',
        'body'      => $row['content']  ?? '',
        'cat'       => $row['category'] ?? 'World News',
        'author'    => $row['author']   ?? 'Staff Reporter',
        'img'       => $row['image']    ?? '',
        'tags'      => array_values(array_filter(array_map('trim', explode(',', $row['tags'] ?? '')))),
        'date'      => $row['date']     ?? date('Y-m-d H:i:s'),
        'published' => (bool)($row['published'] ?? true),   // ← fixed: use actual DB value
        'featured'  => (bool)$row['featured'],
        'breaking'  => (bool)$row['breaking'],
        'views'     => (int)$row['views'],
    ];
}
