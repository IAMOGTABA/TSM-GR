<?php
session_start();
require 'config.php';

if (!isset($_GET['id']) || !isset($_GET['task_id'])) {
    die("Missing subtask or parent task.");
}

$subtask_id = (int) $_GET['id'];
$task_id = (int) $_GET['task_id'];

// Delete the subtask
$stmt = $pdo->prepare("DELETE FROM subtasks WHERE id = :id");
$stmt->execute(['id' => $subtask_id]);

header("Location: view_task.php?task_id=$task_id");
exit;
