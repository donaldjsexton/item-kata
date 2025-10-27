<?php
declare(strict_types=1);

session_start();
header_remove("X-Powered-By");

/* CORS */
if (isset($_SERVER["HTTP_ORIGIN"])) {
    $origin = $_SERVER["HTTP_ORIGIN"];
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit();
}

/* DB */
$dsn = "sqlite:" . __DIR__ . "/app.sqlite"; // FIX: needs "sqlite:" prefix
try {
    $db = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec(
        'CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            done INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo "Server error";
    exit();
}

/* CSRF */
if (empty($_SESSION["csrf"])) {
    $_SESSION["csrf"] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION["csrf"];

/* Render SPA */
function render_spa(string $csrf): void
{
    header("Content-Type: text/html; charset=utf-8"); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Items</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font: 16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
.wrap { max-width: 720px; margin: 32px auto; padding: 0 16px; }
.row { display: flex; gap: 8px; margin-bottom: 12px; }
.row.search { align-items: center; }
.row.search input { flex: 1; padding: 8px 10px; }
input[type="text"], input:not([type]) { flex: 1; padding: 8px 10px; }
button { padding: 8px 12px; cursor: pointer; }
.error { color: #b00020; margin: 4px 0; }
.field-error { color: #b05a00; margin: 8px 0; }
.list { list-style: none; padding: 0; margin: 12px; display: grid; gap: 8px; }
.list li { display: flex; align-items: center; gap: 8px; padding: 8px; border: 1px solid #eee; border-radius: 6px; }
.list li.done span { text-decoration: line-through; opacity: .7; }
.actions { margin-left: auto; display: flex; gap: 6px; }
.pager { display: flex; align-items: center; gap: 12px; justify-content: center; margin: 16px 0; }
</style>
</head>
<body>
<script>
window.__CFG__ = { csrf: "<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" };
</script>
<div id="root" class="wrap"></div>
<script src="/static/bundle.js"></script>
</body>
</html>
<?php exit();
}

/* JSON helpers */
function json_read(): array
{
    $raw = file_get_contents("php://input") ?: "";
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function json_out($data, int $code = 200): void
{
    header("Content-Type: application/json; charset=utf-8"); // FIX: spelling
    http_response_code($code);
    echo json_encode($data);
    exit();
}
function sanitize_title(string $s): string
{
    $s = trim($s);
    if (mb_strlen($s) > 120) {
        $s = mb_substr($s, 0, 120);
    }
    return $s;
}

/* Serve SPA on GET / */
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    // If you want a JSON API at GET /, remove this and add router.php. For now we render SPA:
    render_spa($csrf);
}

/* API */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $in = json_read();
    $action = $in["action"] ?? "";

    if ($action !== "list") {
        $hdr = $in["csrf"] ?? "";
        if ($hdr === "" || !hash_equals($csrf, $hdr)) {
            json_out(["error" => "csrf"], 419);
        }
    }

    try {
        switch ($action) {
            case "list":
                $limit = max(1, min((int) ($in["limit"] ?? 20), 100));
                $offset = max(0, (int) ($in["offset"] ?? 0));
                $q = trim((string) ($in["q"] ?? ""));

                $sql = "SELECT id, title, done, created_at FROM items";
                $params = [];
                if ($q !== "") {
                    $sql .= " WHERE title LIKE :q";
                    $params[":q"] = "%$q%";
                }
                $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

                $stmt = $GLOBALS["db"]->prepare($sql);
                $stmt->bindValue(":limit", $limit + 1, PDO::PARAM_INT);
                $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
                foreach ($params as $k => $v) {
                    $stmt->bindValue($k, $v);
                }
                $stmt->execute();
                $rows = $stmt->fetchAll();

                $has_more = count($rows) > $limit;
                if ($has_more) {
                    array_pop($rows);
                }

                json_out([
                    "items" => $rows,
                    "has_more" => $has_more,
                    "csrf" => $csrf,
                ]);

            case "create":
                $title = sanitize_title((string) ($in["title"] ?? ""));
                if ($title === "") {
                    json_out(
                        ["errors" => ["title" => "Title is required"]],
                        422,
                    );
                }
                $stmt = $GLOBALS["db"]->prepare(
                    "INSERT INTO items (title, done) VALUES (:t, 0)",
                ); // FIX: comma
                $stmt->execute([":t" => $title]);

                $id = (int) $GLOBALS["db"]->lastInsertId();
                $row = $GLOBALS["db"]
                    ->query(
                        "SELECT id, title, done, created_at FROM items WHERE id = $id",
                    )
                    ->fetch();

                json_out($row, 201);

            case "update":
                $id = (int) ($in["id"] ?? 0);
                if ($id <= 0) {
                    json_out(["error" => "bad id"], 422);
                }

                $fields = [];
                $params = [":id" => $id];

                if (array_key_exists("title", $in)) {
                    $t = sanitize_title((string) $in["title"]);
                    if ($t === "") {
                        json_out(
                            ["errors" => ["title" => "Title is required"]],
                            422,
                        );
                    }
                    $fields[] = "title = :t";
                    $params[":t"] = $t;
                }
                if (array_key_exists("done", $in)) {
                    $fields[] = "done = :d";
                    $params[":d"] = (int) !!$in["done"];
                }
                if (!$fields) {
                    json_out(["error" => "nothing to update"], 422);
                }

                $sql =
                    "UPDATE items SET " .
                    implode(", ", $fields) .
                    " WHERE id = :id";
                $stmt = $GLOBALS["db"]->prepare($sql);
                $stmt->execute($params);

                $row = $GLOBALS["db"]->prepare(
                    "SELECT id, title, done, created_at FROM items WHERE id = :id",
                );
                $row->execute([":id" => $id]);
                $r = $row->fetch();
                if (!$r) {
                    json_out(["error" => "not found"], 404);
                }

                json_out($r);

            case "delete":
                $id = (int) ($in["id"] ?? 0);
                if ($id <= 0) {
                    json_out(["error" => "bad id"], 422);
                } // FIX: second arg, not concatenation
                $stmt = $GLOBALS["db"]->prepare(
                    "DELETE FROM items WHERE id = :id",
                );
                $stmt->execute([":id" => $id]);
                json_out(["ok" => true]);

            default:
                json_out(["error" => "unknown action"], 400);
        }
    } catch (Throwable $e) {
        json_out(["error" => "server error"], 500);
    }
}

http_response_code(405);
echo "Method not allowed";
