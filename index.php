<?php
// index.php - Jedno-souborový jednoduchý blog s MongoDB (PHP MongoDB Driver required)

// ===== Konfigurace =====
$mongoUri = 'mongodb://localhost:27017'; // pokud je MongoDB na hostu
$dbName = 'blog';
$collectionName = 'posts';

// ===== Pomocné funkce =====
function getManager($uri) {
    try {
        return new MongoDB\Driver\Manager($uri);
    } catch (Throwable $e) {
        http_response_code(500);
        echo "<h2>Chyba připojení k MongoDB:</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
}

function findAllPosts($manager, $db, $coll) {
    $namespace = $db . '.' . $coll;
    $query = new MongoDB\Driver\Query([], ['sort' => ['created_at' => -1]]);
    $cursor = $manager->executeQuery($namespace, $query);
    return iterator_to_array($cursor);
}

function findPostById($manager, $db, $coll, $id) {
    try {
        $oid = new MongoDB\BSON\ObjectId($id);
    } catch (Throwable $e) {
        return null;
    }
    $namespace = $db . '.' . $coll;
    $query = new MongoDB\Driver\Query(['_id' => $oid], ['limit' => 1]);
    $cursor = $manager->executeQuery($namespace, $query);
    $arr = iterator_to_array($cursor);
    return count($arr) ? $arr[0] : null;
}

function createPost($manager, $db, $coll, $title, $body) {
    $bulk = new MongoDB\Driver\BulkWrite;
    $doc = [
        'title' => $title,
        'body' => $body,
        'created_at' => new MongoDB\BSON\UTCDateTime((int)(microtime(true)*1000)),
    ];
    $id = $bulk->insert($doc);
    $manager->executeBulkWrite($db . '.' . $coll, $bulk);
    return (string)$id;
}

function deletePost($manager, $db, $coll, $id) {
    try {
        $oid = new MongoDB\BSON\ObjectId($id);
    } catch (Throwable $e) {
        return false;
    }
    $bulk = new MongoDB\Driver\BulkWrite;
    $bulk->delete(['_id' => $oid], ['limit' => 1]);
    $result = $manager->executeBulkWrite($db . '.' . $coll, $bulk);
    return $result->getDeletedCount() > 0;
}

// ===== Zpracování požadavků (create/delete) =====
$manager = getManager($mongoUri);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vložit nový příspěvek
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        if ($title === '' || $body === '') {
            $flash = "Vyplňte prosím titul a obsah.";
        } else {
            try {
                createPost($manager, $dbName, $collectionName, $title, $body);
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } catch (Throwable $e) {
                $flash = "Chyba při ukládání: " . $e->getMessage();
            }
        }
    }

    // Smazat příspěvek
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $id = $_POST['id'];
        try {
            if (deletePost($manager, $dbName, $collectionName, $id)) {
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $flash = "Příspěvek neexistuje nebo se nepodařilo smazat.";
            }
        } catch (Throwable $e) {
            $flash = "Chyba při mazání: " . $e->getMessage();
        }
    }
}

// Získat ID z GET pro zobrazení detailu
$viewPost = null;
if (isset($_GET['view'])) {
    $viewPost = findPostById($manager, $dbName, $collectionName, $_GET['view']);
}

// Načteme seznam příspěvků (pokud nejdeme do detailu)
$posts = $viewPost ? [] : findAllPosts($manager, $dbName, $collectionName);

// ===== HTML výstup =====
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jednoduchý blog (MongoDB + PHP)</title>
<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;background:#f6f7fb;color:#111}
    .wrap{max-width:900px;margin:36px auto;padding:24px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(20,30,50,0.08)}
    h1{margin:0 0 12px;font-size:28px}
    form{margin:18px 0;padding:12px;border:1px dashed #e3e7ef;border-radius:8px}
    input[type=text],textarea{width:100%;padding:10px;margin:6px 0;border:1px solid #d7dbe6;border-radius:6px;font-size:14px}
    button{padding:8px 14px;border-radius:8px;border:0;background:#2563eb;color:#fff;cursor:pointer}
    .post{padding:14px;border-bottom:1px solid #f0f2f7}
    .post h3{margin:0 0 6px}
    .meta{font-size:12px;color:#667085;margin-bottom:8px}
    .actions{margin-top:8px}
    .deleteBtn{background:#ef4444;margin-left:8px}
    .flash{background:#fff3cd;border:1px solid #ffeeba;padding:10px;border-radius:6px;margin-bottom:12px;color:#856404}
    a{color:#2563eb;text-decoration:none}
    .empty{padding:18px;text-align:center;color:#64748b}
    pre{white-space:pre-wrap}
</style>
</head>
<body>
<div class="wrap">
    <h1>Jednoduchý blog</h1>

    <?php if (!empty($flash)): ?>
        <div class="flash"><?=htmlspecialchars($flash)?></div>
    <?php endif; ?>

    <!-- Formulář pro nový příspěvek -->
    <form method="post" onsubmit="return validateForm();">
        <input type="hidden" name="action" value="create">
        <label><strong>Titul</strong></label>
        <input type="text" name="title" id="title" maxlength="200" required>
        <label><strong>Obsah</strong></label>
        <textarea name="body" id="body" rows="6" required></textarea>
        <div style="margin-top:8px">
            <button type="submit">Vytvořit příspěvek</button>
        </div>
    </form>

    <?php if ($viewPost): 
        $createdAt = $viewPost->created_at instanceof MongoDB\BSON\UTCDateTime ? $viewPost->created_at->toDateTime() : null;
    ?>
        <article>
            <h2><?=htmlspecialchars($viewPost->title)?></h2>
            <div class="meta">Publikováno: <?= $createdAt ? $createdAt->format('Y-m-d H:i:s') : '—' ?></div>
            <div><pre><?=htmlspecialchars($viewPost->body)?></pre></div>
            <div class="actions">
                <a href="<?=htmlspecialchars($_SERVER['PHP_SELF'])?>">← zpět na seznam</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Smazat tento příspěvek?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?=htmlspecialchars((string)$viewPost->_id)?>">
                    <button type="submit" class="deleteBtn">Smazat</button>
                </form>
            </div>
        </article>
    <?php else: ?>

        <!-- Seznam příspěvků -->
        <?php if (count($posts) === 0): ?>
            <div class="empty">Žádné příspěvky. Vytvoř první!</div>
        <?php else: ?>
            <?php foreach ($posts as $p): 
                $createdAt = $p->created_at instanceof MongoDB\BSON\UTCDateTime ? $p->created_at->toDateTime() : null;
            ?>
                <div class="post">
                    <h3><a href="<?=htmlspecialchars($_SERVER['PHP_SELF'])?>?view=<?=htmlspecialchars((string)$p->_id)?>"><?=htmlspecialchars($p->title)?></a></h3>
                    <div class="meta">Publikováno: <?= $createdAt ? $createdAt->format('Y-m-d H:i:s') : '—' ?></div>
                    <div style="margin-top:6px"><?=nl2br(htmlspecialchars(substr($p->body, 0, 300)))?><?=strlen($p->body) > 300 ? '…' : ''?></div>
                    <div class="actions">
                        <a href="<?=htmlspecialchars($_SERVER['PHP_SELF'])?>?view=<?=htmlspecialchars((string)$p->_id)?>">Číst</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Opravdu smazat příspěvek?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?=htmlspecialchars((string)$p->_id)?>">
                            <button type="submit" class="deleteBtn">Smazat</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

    <footer style="margin-top:18px;font-size:13px;color:#64748b">
        Tento jednoduchý blog používá MongoDB kolekci <strong><?=htmlspecialchars($dbName . '.' . $collectionName)?></strong> na <?=htmlspecialchars($mongoUri)?>.
    </footer>
</div>

<script>
function validateForm() {
    var t = document.getElementById('title').value.trim();
    var b = document.getElementById('body').value.trim();
    if (!t || !b) {
        alert('Vyplňte prosím titul a obsah.');
        return false;
    }
    return true;
}
</script>
</body>
</html>
