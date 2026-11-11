
<?php
$pageTitle = $pageTitle ?? 'Tokoku';
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?></title>

    <link
        rel="stylesheet"
        href="<?= e(appUrl('assets/css/style.css?v=' . filemtime(__DIR__ . '/../assets/css/style.css'))) ?>"
    >

</head>

<body>
