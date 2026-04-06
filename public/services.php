<?php
session_start();
require __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Handle Add or Edit
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if (!$name || $price <= 0) {
        $error = "სახელის და ფასის ველები სავალდებულოა და ფასი უნდა იყოს > 0";
    } else {
        try {
            if ($id > 0) {
                // Update
                $stmt = $pdo->prepare("UPDATE services SET name = ?, price = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $price, $description, $id]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO services (name, price, description) VALUES (?, ?, ?)");
                $stmt->execute([$name, $price, $description]);
            }
            header('Location: services.php');
            exit;
        } catch (PDOException $e) {
            $error = "შეცდომა: " . $e->getMessage();
        }
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    if ($del_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$del_id]);
        header('Location: services.php');
        exit;
    }
}

// Fetch all services
$stmt = $pdo->query("SELECT * FROM services ORDER BY name");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8" />
    <title>სერვისები და ფასები</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@100..900&display=swap" rel="stylesheet">
    <style>
      body { font-family: "Noto Sans Georgian", sans-serif; max-width: 800px; margin: 20px auto; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 20px;}
      th, td { border: 1px solid #ccc; padding: 8px; text-align: left;}
      th { background-color: #21c1a6; color: white;}
      form { margin-bottom: 20px; }
      label { display: block; margin: 8px 0 4px; }
      input[type="text"], input[type="number"], textarea { width: 100%; padding: 8px; box-sizing: border-box; }
      button { padding: 10px 15px; background-color: #21c1a6; color: white; border: none; cursor: pointer; }
      button:hover { background-color: #1a8a7e; }
      .error { color: red; margin-bottom: 10px; }
      a.delete-link { color: red; text-decoration: none; font-weight: bold; }
      a.delete-link:hover { text-decoration: underline; }
    </style>
  <link rel="stylesheet" href="/css/preclinic-theme.css">
</head>
<body>

<h1>სერვისები და ფასები</h1>

<?php if ($error): ?>
    <div class="error"><?=htmlspecialchars($error)?></div>
<?php endif; ?>

<form method="post" id="serviceForm">
    <input type="hidden" name="id" id="serviceId" value="">
    <label for="name">სერვისის სახელი *</label>
    <input type="text" name="name" id="name" required>
    <label for="price">ფასი *</label>
    <input type="number" step="0.01" min="0" name="price" id="price" required>
    <label for="description">აღწერა</label>
    <textarea name="description" id="description" rows="3"></textarea>
    <button type="submit">შენახვა</button>
</form>

<table>
    <thead>
        <tr>
            <th>სახელი</th>
            <th>ფასი</th>
            <th>აღწერა</th>
            <th>მოქმედება</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($services as $s): ?>
            <tr>
                <td><?=htmlspecialchars($s['name'])?></td>
                <td><?=number_format($s['price'], 2)?></td>
                <td><?=htmlspecialchars($s['description'])?></td>
                <td>
                    <button onclick="editService(<?=json_encode($s)?>)">რედაქტირება</button>
                    <a href="?delete=<?= $s['id'] ?>" class="delete-link" onclick="return confirm('ნამდვილად გსურთ წაშლა?');">წაშლა</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?>
            <tr><td colspan="4" style="text-align:center;">სერვისები არ არის დამატებული</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<script>
function editService(service) {
    document.getElementById('serviceId').value = service.id;
    document.getElementById('name').value = service.name;
    document.getElementById('price').value = service.price;
    document.getElementById('description').value = service.description || '';
    window.scrollTo({top:0, behavior:'smooth'});
}
</script>

</body>
</html>
