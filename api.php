<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Editor-Key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set("display_errors", "0");
ini_set("html_errors", "0");
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

const DB_DIR = __DIR__ . '/data';
const DB_PATH = DB_DIR . '/chatapp.sqlite';
const ADMIN_KEY_FILE = DB_DIR . '/admin_key.txt';
const API_VERSION = '2026-04-04.1';
const DEFAULT_REQUEST_TEXT = '運営にリクエスト';
const LEGACY_DEFAULT_REQUEST_TEXT = '運営側へのリクエスト';
const DEFAULT_REQUEST_AUTHOR = '__system__';
const POLL_MAX_VOTES_PER_USER = 0; // 0 = unlimited
const LIVE_POLL_MAX_HEARTS_PER_USER = 10;
const LIVE_POLL_MAX_HEARTS_PER_USER_MOBILE = 5;
const VOTE_POLL_LIMIT_PER_SESSION = 3;

try {
    $pdo = db();
    ensureSchema($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        handleGet($pdo);
        exit;
    }
    if ($method === 'POST') {
        handlePost($pdo);
        exit;
    }
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

function handleGet(PDO $pdo): void
{
    $action = sanitize($_GET['action'] ?? '', 40);
    if ($action === 'health') {
        respond([
            'ok' => true,
            'service' => 'chat-app-php-api',
            'version' => API_VERSION,
            'deletePolicy' => 'open',
            'time' => gmdate('c'),
            'adminKey' => adminKeyDiagnostics(),
        ]);
        return;
    }

    if ($action === 'listQuestions') {
        $sessionCode = sanitize($_GET['session'] ?? 'default', 40);
        $authorToken = sanitize($_GET['authorToken'] ?? '', 80);
        $sinceRevision = toInt($_GET['sinceRevision'] ?? 0, 0);
        $includeAnswered = toInt($_GET['includeAnswered'] ?? 0, 0) === 1;
        respond(listQuestionsPayload($pdo, $sessionCode, $authorToken, $sinceRevision, $includeAnswered));
        return;
    }

    if ($action === 'getSessionConfig') {
        $sessionCode = sanitize($_GET['session'] ?? 'default', 40);
        respond(['ok' => true, 'config' => getSessionConfigInternal($pdo, $sessionCode)]);
        return;
    }

    if ($action === 'getPoll') {
        $sessionCode = sanitize($_GET['session'] ?? 'default', 40);
        $voterToken = sanitize($_GET['voterToken'] ?? '', 80);
        $pollId = sanitize($_GET['pollId'] ?? '', 64);
        $pollMeta = getPollMeta($pdo, $sessionCode);
        respond([
            'ok' => true,
            'poll' => getPollInternal($pdo, $sessionCode, $voterToken, $pollId),
            'pollList' => listPollSummaries($pdo, $sessionCode),
            'pollCount' => $pollMeta['pollCount'],
            'maxPolls' => $pollMeta['maxPolls'],
            'canCreateMore' => $pollMeta['canCreateMore'],
        ]);
        return;
    }

    if ($action === 'getLivePoll') {
        $sessionCode = sanitize($_GET['session'] ?? 'default', 40);
        $voterToken = sanitize($_GET['voterToken'] ?? '', 80);
        respond(['ok' => true, 'poll' => getLivePollBoard($pdo, $sessionCode, $voterToken)]);
        return;
    }

    if ($action === 'listSessions') {
        respond(['ok' => true, 'sessions' => listSessions($pdo)]);
        return;
    }

    if ($action === 'getVoteDraft') {
        $sessionCode = sanitize($_GET['session'] ?? 'default', 40);
        $authorToken = sanitize($_GET['authorToken'] ?? '', 80);
        respond(['ok' => true, 'draft' => getVoteDraft($pdo, $sessionCode, $authorToken)]);
        return;
    }

    // Emergency compatibility path: GET delete endpoints for old/mobile clients.
    if ($action === 'deleteQuestion' || $action === 'deleteMyQuestion') {
        respond(deleteMyQuestion($pdo, [
            'questionId' => sanitize($_GET['questionId'] ?? '', 64),
            'authorToken' => sanitize($_GET['authorToken'] ?? '', 80),
            'adminKey' => sanitize($_GET['adminKey'] ?? '', 200),
        ]));
        return;
    }
    if ($action === 'deleteReply' || $action === 'deleteMyReply') {
        respond(deleteMyReply($pdo, [
            'replyId' => sanitize($_GET['replyId'] ?? '', 64),
            'authorToken' => sanitize($_GET['authorToken'] ?? '', 80),
            'adminKey' => sanitize($_GET['adminKey'] ?? '', 200),
        ]));
        return;
    }

    if ($action === 'exportCsv') {
        $sessionCode = sanitize($_GET['session'] ?? 'default', 40);
        exportCsv($pdo, $sessionCode);
        return;
    }

    respond([
        'ok' => true,
        'service' => 'chat-app-php-api',
        'usage' => 'POST JSON { action, payload }',
        'actions' => [
            'submitQuestion',
            'submitReply',
            'listQuestions',
            'voteQuestion',
            'voteReply',
            'deleteMyQuestion',
            'deleteMyReply',
            'editQuestion',
            'editReply',
            'updateQuestionMeta',
            'verifyAdminKey',
            'getSessionConfig',
            'setSessionConfig',
            'listSessions',
            'getVoteDraft',
            'saveVoteDraft',
            'createSession',
            'hideSession',
            'renameSession',
            'getPoll',
            'getLivePoll',
            'createPoll',
            'updatePollTarget',
            'votePoll',
            'closePoll',
            'clearPoll',
            'clearQuestions',
            'submitLivePoll',
            'adjustLivePoll',
            'clearLivePoll',
            'deleteLivePollTopic',
        ],
    ]);
}

function handlePost(PDO $pdo): void
{
    $body = parseJsonBody();
    $action = sanitize($body['action'] ?? '', 40);
    $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

    switch ($action) {
        case 'submitQuestion':
            respond(submitQuestion($pdo, $payload));
            return;
        case 'submitReply':
            respond(submitReply($pdo, $payload));
            return;
        case 'listQuestions':
            respond(listQuestionsPayload(
                $pdo,
                sanitize($payload['sessionCode'] ?? 'default', 40),
                sanitize($payload['authorToken'] ?? '', 80),
                toInt($payload['sinceRevision'] ?? 0, 0),
                toInt($payload['includeAnswered'] ?? 0, 0) === 1
            ));
            return;
        case 'voteQuestion':
            respond(voteQuestion($pdo, $payload));
            return;
        case 'voteReply':
            respond(voteReply($pdo, $payload));
            return;
        case 'deleteMyQuestion':
            respond(deleteMyQuestion($pdo, $payload));
            return;
        case 'deleteQuestion':
            respond(deleteMyQuestion($pdo, $payload));
            return;
        case 'deleteMyReply':
            respond(deleteMyReply($pdo, $payload));
            return;
        case 'deleteReply':
            respond(deleteMyReply($pdo, $payload));
            return;
        case 'updateQuestionMeta':
            requireAdminPayload($payload);
            respond(updateQuestionMeta($pdo, $payload));
            return;
        case 'editQuestion':
            requireAdminPayload($payload);
            respond(editQuestion($pdo, $payload));
            return;
        case 'editReply':
            requireAdminPayload($payload);
            respond(editReply($pdo, $payload));
            return;
        case 'verifyAdminKey':
            requireAdminPayload($payload);
            respond(['ok' => true, 'verified' => true]);
            return;
        case 'changeAdminKey':
            requireAdminPayload($payload);
            respond(changeAdminKey($payload));
            return;
        case 'getSessionConfig':
            respond(['ok' => true, 'config' => getSessionConfigInternal($pdo, sanitize($payload['sessionCode'] ?? 'default', 40))]);
            return;
        case 'setSessionConfig':
            requireAdminPayload($payload);
            respond(setSessionConfig($pdo, $payload));
            return;
        case 'listSessions':
            respond(['ok' => true, 'sessions' => listSessions($pdo)]);
            return;
        case 'getVoteDraft':
            respond(['ok' => true, 'draft' => getVoteDraft(
                $pdo,
                sanitize($payload['sessionCode'] ?? 'default', 40),
                sanitize($payload['authorToken'] ?? '', 80)
            )]);
            return;
        case 'saveVoteDraft':
            respond(saveVoteDraft($pdo, $payload));
            return;
        case 'createSession':
            requireAdminPayload($payload);
            respond(createSession($pdo, $payload));
            return;
        case 'hideSession':
            requireAdminPayload($payload);
            respond(hideSession($pdo, $payload));
            return;
        case 'renameSession':
            requireAdminPayload($payload);
            respond(renameSessionData($pdo, $payload));
            return;
        case 'getPoll':
            $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
            $pollMeta = getPollMeta($pdo, $sessionCode);
            respond([
                'ok' => true,
                'poll' => getPollInternal(
                    $pdo,
                    $sessionCode,
                    sanitize($payload['voterToken'] ?? '', 80),
                    sanitize($payload['pollId'] ?? '', 64)
                ),
                'pollList' => listPollSummaries($pdo, $sessionCode),
                'pollCount' => $pollMeta['pollCount'],
                'maxPolls' => $pollMeta['maxPolls'],
                'canCreateMore' => $pollMeta['canCreateMore'],
            ]);
            return;
        case 'getLivePoll':
            respond(['ok' => true, 'poll' => getLivePollBoard(
                $pdo,
                sanitize($payload['sessionCode'] ?? 'default', 40),
                sanitize($payload['voterToken'] ?? '', 80)
            )]);
            return;
        case 'createPoll':
            requireAdminPayload($payload);
            respond(createPoll($pdo, $payload));
            return;
        case 'updatePollTarget':
            requireAdminPayload($payload);
            respond(updatePollTarget($pdo, $payload));
            return;
        case 'votePoll':
            respond(votePoll($pdo, $payload));
            return;
        case 'closePoll':
            requireAdminPayload($payload);
            respond(closePoll($pdo, $payload));
            return;
        case 'clearPoll':
            requireAdminPayload($payload);
            respond(clearPoll($pdo, $payload));
            return;
        case 'clearQuestions':
            requireAdminPayload($payload);
            respond(clearQuestionsData($pdo, $payload));
            return;
        case 'submitLivePoll':
            respond(submitLivePoll($pdo, $payload));
            return;
        case 'adjustLivePoll':
            requireAdminPayload($payload);
            respond(adjustLivePoll($pdo, $payload));
            return;
        case 'clearLivePoll':
            requireAdminPayload($payload);
            respond(clearLivePoll($pdo, $payload));
            return;
        case 'deleteLivePollTopic':
            requireAdminPayload($payload);
            respond(deleteLivePollTopic($pdo, $payload));
            return;
        default:
            respond(['ok' => false, 'error' => 'Unknown action'], 400);
    }
}

function submitQuestion(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $displayName = sanitize($payload['displayName'] ?? '', 40);
    $questionText = sanitize($payload['questionText'] ?? '', 400);
    $authorToken = sanitize($payload['authorToken'] ?? '', 80);
    $config = getSessionConfigInternal($pdo, $sessionCode);

    if ($questionText === '') {
        throw new RuntimeException('質問内容は必須です');
    }
    if ($authorToken === '') {
        throw new RuntimeException('authorToken が必要です');
    }
    if ($config['anonymousOnly']) {
        $displayName = '匿名';
    } elseif ($displayName === '') {
        throw new RuntimeException('実名運用中のため名前は必須です');
    }

    $id = uuidv4();
    $now = nowIso();

    $stmt = $pdo->prepare('INSERT INTO questions (id, created_at, session_code, display_name, question_text, votes, status, author_token, pinned) VALUES (:id, :created_at, :session_code, :display_name, :question_text, 0, "OPEN", :author_token, 0)');
    $stmt->execute([
        ':id' => $id,
        ':created_at' => $now,
        ':session_code' => $sessionCode,
        ':display_name' => $displayName,
        ':question_text' => $questionText,
        ':author_token' => $authorToken,
    ]);

    bumpSessionRevision($pdo, $sessionCode);
    return ['ok' => true, 'id' => $id];
}

function submitReply(PDO $pdo, array $payload): array
{
    $questionId = sanitize($payload['questionId'] ?? '', 64);
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $displayName = sanitize($payload['displayName'] ?? '匿名', 40);
    $replyText = sanitize($payload['replyText'] ?? '', 300);
    $authorToken = sanitize($payload['authorToken'] ?? '', 80);

    if ($questionId === '' || $replyText === '' || $authorToken === '') {
        throw new RuntimeException('返信内容または識別情報が不足しています');
    }

    $q = $pdo->prepare('SELECT id FROM questions WHERE id = :id AND session_code = :session_code LIMIT 1');
    $q->execute([':id' => $questionId, ':session_code' => $sessionCode]);
    if (!$q->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('対象の投稿が見つかりません');
    }

    $id = uuidv4();
    $stmt = $pdo->prepare('INSERT INTO replies (id, question_id, created_at, session_code, display_name, reply_text, author_token, status, votes) VALUES (:id, :question_id, :created_at, :session_code, :display_name, :reply_text, :author_token, "OPEN", 0)');
    $stmt->execute([
        ':id' => $id,
        ':question_id' => $questionId,
        ':created_at' => nowIso(),
        ':session_code' => $sessionCode,
        ':display_name' => $displayName,
        ':reply_text' => $replyText,
        ':author_token' => $authorToken,
    ]);

    bumpSessionRevision($pdo, $sessionCode);
    return ['ok' => true, 'id' => $id];
}

function listQuestionsPayload(PDO $pdo, string $sessionCode, string $authorToken, int $sinceRevision, bool $includeAnswered = false): array
{
    $currentRevision = getSessionRevision($pdo, $sessionCode);
    if ($sinceRevision >= $currentRevision && $currentRevision > 0) {
        return ['ok' => true, 'changed' => false, 'revision' => $currentRevision, 'data' => []];
    }

    $data = listQuestions($pdo, $sessionCode, $authorToken, $includeAnswered);
    if ($currentRevision === 0) {
        $currentRevision = 1;
        setSessionRevision($pdo, $sessionCode, $currentRevision);
    }

    return ['ok' => true, 'changed' => true, 'revision' => $currentRevision, 'data' => $data];
}

function ensureDefaultRequestQuestion(PDO $pdo, string $sessionCode): void
{
    $settings = fetchOne($pdo, 'SELECT default_request_disabled FROM session_settings WHERE session_code = :session_code LIMIT 1', [
        ':session_code' => $sessionCode,
    ]);
    if ($settings && toInt($settings['default_request_disabled'] ?? 0, 0) === 1) {
        return;
    }

    $existing = fetchOne($pdo, 'SELECT id FROM questions WHERE session_code = :session_code AND author_token = :author_token AND question_text IN (:text1, :text2) LIMIT 1', [
        ':session_code' => $sessionCode,
        ':author_token' => DEFAULT_REQUEST_AUTHOR,
        ':text1' => DEFAULT_REQUEST_TEXT,
        ':text2' => LEGACY_DEFAULT_REQUEST_TEXT,
    ]);
    if ($existing) {
        return;
    }

    execStmt($pdo, 'INSERT INTO questions (id, created_at, session_code, display_name, question_text, votes, status, author_token, pinned) VALUES (:id, :created_at, :session_code, :display_name, :question_text, 0, "OPEN", :author_token, 1)', [
        ':id' => uuidv4(),
        ':created_at' => nowIso(),
        ':session_code' => $sessionCode,
        ':display_name' => '匿名',
        ':question_text' => DEFAULT_REQUEST_TEXT,
        ':author_token' => DEFAULT_REQUEST_AUTHOR,
    ]);
    bumpSessionRevision($pdo, $sessionCode);
}

function listQuestions(PDO $pdo, string $sessionCode, string $authorToken, bool $includeAnswered = false): array
{
    $statusFilter = $includeAnswered ? 'status IN ("OPEN","ANSWERED")' : 'status = "OPEN"';
    $q = $pdo->prepare("SELECT id, created_at, session_code, display_name, question_text, votes, status, author_token, pinned FROM questions WHERE session_code = :session_code AND $statusFilter ORDER BY pinned DESC, votes DESC, created_at ASC");
    $q->execute([':session_code' => $sessionCode]);
    $questions = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$questions) {
        return [];
    }

    $ids = array_map(fn(array $r): string => (string) $r['id'], $questions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $r = $pdo->prepare("SELECT id, question_id, created_at, display_name, reply_text, votes, author_token FROM replies WHERE session_code = ? AND status = 'OPEN' AND question_id IN ($placeholders) ORDER BY created_at ASC");
    $r->execute(array_merge([$sessionCode], $ids));
    $replies = $r->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $replyMap = [];
    foreach ($replies as $row) {
        $qid = (string) $row['question_id'];
        if (!isset($replyMap[$qid])) {
            $replyMap[$qid] = [];
        }
        $replyMap[$qid][] = [
            'id' => (string) $row['id'],
            'createdAt' => (string) $row['created_at'],
            'displayName' => (string) $row['display_name'],
            'replyText' => (string) $row['reply_text'],
            'votes' => (int) $row['votes'],
            'isMine' => $authorToken !== '' && (string) $row['author_token'] === $authorToken,
        ];
    }

    $out = [];
    foreach ($questions as $row) {
        $id = (string) $row['id'];
        $out[] = [
            'id' => $id,
            'createdAt' => (string) $row['created_at'],
            'sessionCode' => (string) $row['session_code'],
            'displayName' => (string) $row['display_name'],
            'questionText' => (string) $row['question_text'],
            'votes' => (int) $row['votes'],
            'status' => (string) $row['status'],
            'pinned' => toInt($row['pinned'] ?? 0, 0) === 1,
            'isMine' => $authorToken !== '' && (string) $row['author_token'] === $authorToken,
            'replies' => $replyMap[$id] ?? [],
        ];
    }

    return $out;
}

function voteQuestion(PDO $pdo, array $payload): array
{
    $questionId = sanitize($payload['questionId'] ?? '', 64);
    $voterToken = sanitize($payload['voterToken'] ?? '', 80);
    if ($questionId === '' || $voterToken === '') {
        throw new RuntimeException('不正な投票リクエストです');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code, votes FROM questions WHERE id = :id LIMIT 1', [':id' => $questionId]);
        if (!$row) {
            throw new RuntimeException('対象の質問が見つかりません');
        }

        execStmt($pdo, 'UPDATE questions SET votes = votes + 1 WHERE id = :id', [':id' => $questionId]);
        execStmt($pdo, 'INSERT INTO votes (entity_id, voter_token, created_at) VALUES (:entity_id, :voter_token, :created_at)', [
            ':entity_id' => $questionId,
            ':voter_token' => $voterToken,
            ':created_at' => nowIso(),
        ]);

        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true, 'duplicated' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function voteReply(PDO $pdo, array $payload): array
{
    $replyId = sanitize($payload['replyId'] ?? '', 64);
    $voterToken = sanitize($payload['voterToken'] ?? '', 80);
    if ($replyId === '' || $voterToken === '') {
        throw new RuntimeException('不正な投票リクエストです');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code FROM replies WHERE id = :id LIMIT 1', [':id' => $replyId]);
        if (!$row) {
            throw new RuntimeException('対象の返信が見つかりません');
        }

        execStmt($pdo, 'UPDATE replies SET votes = votes + 1 WHERE id = :id', [':id' => $replyId]);
        execStmt($pdo, 'INSERT INTO votes (entity_id, voter_token, created_at) VALUES (:entity_id, :voter_token, :created_at)', [
            ':entity_id' => $replyId,
            ':voter_token' => $voterToken,
            ':created_at' => nowIso(),
        ]);

        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true, 'duplicated' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function deleteMyQuestion(PDO $pdo, array $payload): array
{
    $questionId = sanitize($payload['questionId'] ?? '', 64);
    if ($questionId === '') {
        throw new RuntimeException('不正な削除リクエストです');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code, author_token, question_text FROM questions WHERE id = :id LIMIT 1', [':id' => $questionId]);
        if (!$row) {
            throw new RuntimeException('対象の質問が見つかりません');
        }

        $isAdmin = isAdminPayload($payload);
        if (!$isAdmin) {
            $authorToken = sanitize($payload['authorToken'] ?? '', 80);
            if ($authorToken === '') {
                throw new RuntimeException('authorToken が必要です');
            }
            if (!hash_equals((string) ($row['author_token'] ?? ''), $authorToken)) {
                throw new RuntimeException('自分の投稿のみ削除できます');
            }
        }

        if (
            (string) ($row['author_token'] ?? '') === DEFAULT_REQUEST_AUTHOR
            && (
                (string) ($row['question_text'] ?? '') === DEFAULT_REQUEST_TEXT
                || (string) ($row['question_text'] ?? '') === LEGACY_DEFAULT_REQUEST_TEXT
            )
        ) {
            execStmt($pdo, 'INSERT INTO session_settings (session_code, anonymous_only, tip_url, metric_labels_json, default_request_disabled) VALUES (:session_code, 1, "", "[]", 1) ON CONFLICT(session_code) DO UPDATE SET default_request_disabled = 1', [
                ':session_code' => (string) $row['session_code'],
            ]);
        }

        execStmt($pdo, 'DELETE FROM questions WHERE id = :id', [':id' => $questionId]);
        execStmt($pdo, 'DELETE FROM votes WHERE entity_id = :id', [':id' => $questionId]);
        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function deleteMyReply(PDO $pdo, array $payload): array
{
    $replyId = sanitize($payload['replyId'] ?? '', 64);
    if ($replyId === '') {
        throw new RuntimeException('不正な削除リクエストです');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code, author_token FROM replies WHERE id = :id LIMIT 1', [':id' => $replyId]);
        if (!$row) {
            throw new RuntimeException('対象の返信が見つかりません');
        }

        $isAdmin = isAdminPayload($payload);
        if (!$isAdmin) {
            $authorToken = sanitize($payload['authorToken'] ?? '', 80);
            if ($authorToken === '') {
                throw new RuntimeException('authorToken が必要です');
            }
            if (!hash_equals((string) ($row['author_token'] ?? ''), $authorToken)) {
                throw new RuntimeException('自分の返信のみ削除できます');
            }
        }

        execStmt($pdo, 'DELETE FROM replies WHERE id = :id', [':id' => $replyId]);
        execStmt($pdo, 'DELETE FROM votes WHERE entity_id = :id', [':id' => $replyId]);
        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function editQuestion(PDO $pdo, array $payload): array
{
    $questionId = sanitize($payload['questionId'] ?? '', 64);
    $questionText = sanitize($payload['questionText'] ?? '', 400);
    if ($questionId === '' || $questionText === '') {
        throw new RuntimeException('質問編集パラメータが不正です');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code FROM questions WHERE id = :id LIMIT 1', [':id' => $questionId]);
        if (!$row) {
            throw new RuntimeException('対象の質問が見つかりません');
        }
        execStmt($pdo, 'UPDATE questions SET question_text = :question_text WHERE id = :id', [
            ':question_text' => $questionText,
            ':id' => $questionId,
        ]);
        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function editReply(PDO $pdo, array $payload): array
{
    $replyId = sanitize($payload['replyId'] ?? '', 64);
    $replyText = sanitize($payload['replyText'] ?? '', 300);
    if ($replyId === '' || $replyText === '') {
        throw new RuntimeException('コメント編集パラメータが不正です');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code FROM replies WHERE id = :id LIMIT 1', [':id' => $replyId]);
        if (!$row) {
            throw new RuntimeException('対象のコメントが見つかりません');
        }
        execStmt($pdo, 'UPDATE replies SET reply_text = :reply_text WHERE id = :id', [
            ':reply_text' => $replyText,
            ':id' => $replyId,
        ]);
        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function updateQuestionMeta(PDO $pdo, array $payload): array
{
    $questionId = sanitize($payload['questionId'] ?? '', 64);
    $status = strtoupper(sanitize($payload['status'] ?? 'OPEN', 20));
    $pinned = toInt($payload['pinned'] ?? 0, 0) === 1 ? 1 : 0;
    if ($questionId === '') {
        throw new RuntimeException('対象の質問IDが不正です');
    }
    if (!in_array($status, ['OPEN', 'ANSWERED'], true)) {
        throw new RuntimeException('ステータスが不正です');
    }

    $pdo->beginTransaction();
    try {
        $row = fetchOne($pdo, 'SELECT id, session_code FROM questions WHERE id = :id LIMIT 1', [':id' => $questionId]);
        if (!$row) {
            throw new RuntimeException('対象の質問が見つかりません');
        }
        execStmt($pdo, 'UPDATE questions SET status = :status, pinned = :pinned WHERE id = :id', [
            ':status' => $status,
            ':pinned' => $pinned,
            ':id' => $questionId,
        ]);
        bumpSessionRevision($pdo, (string) $row['session_code']);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getSessionConfigInternal(PDO $pdo, string $sessionCode): array
{
    $row = fetchOne($pdo, 'SELECT anonymous_only, tip_url, survey_url, metric_labels_json FROM session_settings WHERE session_code = :session_code LIMIT 1', [
        ':session_code' => $sessionCode,
    ]);
    if (!$row) {
        return ['anonymousOnly' => true, 'tipUrl' => '', 'surveyUrl' => '', 'metricLabels' => defaultMetricLabels()];
    }
    $metricLabels = normalizeMetricLabels($row['metric_labels_json'] ?? null);
    return [
        'anonymousOnly' => toInt($row['anonymous_only'], 1) === 1,
        'tipUrl' => (string) ($row['tip_url'] ?? ''),
        'surveyUrl' => (string) ($row['survey_url'] ?? ''),
        'metricLabels' => $metricLabels,
    ];
}

function setSessionConfig(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $current = getSessionConfigInternal($pdo, $sessionCode);
    $anonymousOnly = array_key_exists('anonymousOnly', $payload)
        ? (toInt($payload['anonymousOnly'] ?? 1, 1) === 1 ? 1 : 0)
        : ($current['anonymousOnly'] ? 1 : 0);
    $tipUrl = array_key_exists('tipUrl', $payload)
        ? sanitizeUrl($payload['tipUrl'] ?? '')
        : (string) ($current['tipUrl'] ?? '');
    $metricLabels = array_key_exists('metricLabels', $payload)
        ? normalizeMetricLabels($payload['metricLabels'] ?? null)
        : normalizeMetricLabels($current['metricLabels'] ?? null);
    $surveyUrl = array_key_exists('surveyUrl', $payload)
        ? sanitizeUrl($payload['surveyUrl'] ?? '')
        : (string) ($current['surveyUrl'] ?? '');
    $metricLabelsJson = json_encode($metricLabels, JSON_UNESCAPED_UNICODE);
    if ($metricLabelsJson === false) {
        throw new RuntimeException('metricLabels の保存に失敗しました');
    }

    execStmt($pdo, 'INSERT INTO session_settings (session_code, anonymous_only, tip_url, survey_url, metric_labels_json) VALUES (:session_code, :anonymous_only, :tip_url, :survey_url, :metric_labels_json) ON CONFLICT(session_code) DO UPDATE SET anonymous_only = :anonymous_only, tip_url = :tip_url, survey_url = :survey_url, metric_labels_json = :metric_labels_json', [
        ':session_code' => $sessionCode,
        ':anonymous_only' => $anonymousOnly,
        ':tip_url' => $tipUrl,
        ':survey_url' => $surveyUrl,
        ':metric_labels_json' => $metricLabelsJson,
    ]);
    bumpSessionRevision($pdo, $sessionCode);
    return ['ok' => true, 'config' => ['anonymousOnly' => $anonymousOnly === 1, 'tipUrl' => $tipUrl, 'surveyUrl' => $surveyUrl, 'metricLabels' => $metricLabels]];
}

function getPollInternal(PDO $pdo, string $sessionCode, string $voterToken, string $pollId = ''): ?array
{
    $row = null;
    if ($pollId !== '') {
        $row = fetchOne($pdo, 'SELECT id, session_code, question_text, options_json, poll_type, status, target_votes, max_votes_per_user, timer_minutes, ends_at, created_at, updated_at FROM polls WHERE session_code = :session_code AND id = :id LIMIT 1', [
            ':session_code' => $sessionCode,
            ':id' => $pollId,
        ]);
    }
    if (!$row) {
        $row = fetchOne($pdo, 'SELECT id, session_code, question_text, options_json, poll_type, status, target_votes, max_votes_per_user, timer_minutes, ends_at, created_at, updated_at FROM polls WHERE session_code = :session_code ORDER BY created_at DESC, updated_at DESC, rowid DESC LIMIT 1', [
            ':session_code' => $sessionCode,
        ]);
    }
    if (!$row) {
        return null;
    }
    $pollType = strtoupper((string) ($row['poll_type'] ?? 'CHOICE')) === 'TEXT' ? 'TEXT' : 'CHOICE';
    $options = json_decode((string) ($row['options_json'] ?? '[]'), true);
    if (!is_array($options)) {
        $options = [];
    }
    $counts = [];
    $totalVotes = 0;
    $myVote = null;
    $myVotes = [];
    $myChoiceCounts = [];
    $myVoteTotal = 0;
    $myText = null;
    $responses = [];

    if ($pollType === 'CHOICE') {
        $counts = array_fill(0, count($options), 0);
        $stmt = $pdo->prepare('SELECT choice_index, COUNT(*) AS c FROM (
            SELECT choice_index FROM poll_votes WHERE poll_id = :poll_id
            UNION ALL
            SELECT choice_index FROM poll_choice_votes WHERE poll_id = :poll_id
        ) v GROUP BY choice_index');
        $stmt->execute([':poll_id' => (string) $row['id']]);
        $voteRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($voteRows as $vr) {
            $idx = toInt($vr['choice_index'] ?? -1, -1);
            if ($idx >= 0 && $idx < count($counts)) {
                $counts[$idx] = toInt($vr['c'] ?? 0, 0);
            }
        }
        $totalVotes = array_sum($counts);
        $myChoiceCounts = array_fill(0, count($options), 0);
        if ($voterToken !== '') {
            $mineStmt = $pdo->prepare('SELECT choice_index FROM (
                SELECT choice_index FROM poll_votes WHERE poll_id = :poll_id AND voter_token = :voter_token
                UNION ALL
                SELECT choice_index FROM poll_choice_votes WHERE poll_id = :poll_id AND voter_token = :voter_token
            ) m');
            $mineStmt->execute([
                ':poll_id' => (string) $row['id'],
                ':voter_token' => $voterToken,
            ]);
            $mineRows = $mineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($mineRows as $mr) {
                $idx = toInt($mr['choice_index'] ?? -1, -1);
                if ($idx >= 0 && $idx < count($options)) {
                    $myVoteTotal += 1;
                    $myChoiceCounts[$idx] = toInt($myChoiceCounts[$idx] ?? 0, 0) + 1;
                    if (!in_array($idx, $myVotes, true)) {
                        $myVotes[] = $idx;
                    }
                }
            }
            if (count($myVotes) > 0) {
                $myVote = $myVotes[0];
            }
        }
    } else {
        $countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM poll_text_answers WHERE poll_id = :poll_id');
        $countStmt->execute([':poll_id' => (string) $row['id']]);
        $totalVotes = toInt($countStmt->fetchColumn(), 0);
        $respStmt = $pdo->prepare('SELECT answer_text, created_at FROM poll_text_answers WHERE poll_id = :poll_id ORDER BY created_at DESC LIMIT 30');
        $respStmt->execute([':poll_id' => (string) $row['id']]);
        $respRows = $respStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($respRows as $rr) {
            $responses[] = [
                'text' => (string) ($rr['answer_text'] ?? ''),
                'createdAt' => (string) ($rr['created_at'] ?? ''),
            ];
        }
        if ($voterToken !== '') {
            $mine = fetchOne($pdo, 'SELECT answer_text FROM poll_text_answers WHERE poll_id = :poll_id AND voter_token = :voter_token LIMIT 1', [
                ':poll_id' => (string) $row['id'],
                ':voter_token' => $voterToken,
            ]);
            if ($mine) {
                $myText = (string) ($mine['answer_text'] ?? '');
            }
        }
    }
    $targetVotes = max(10, min(1000, toInt($row['target_votes'] ?? 50, 50)));
    $maxVotesPerUser = normalizeMaxVotesPerUser($row['max_votes_per_user'] ?? POLL_MAX_VOTES_PER_USER, POLL_MAX_VOTES_PER_USER);
    $timerMinutes = normalizeTimerMinutes($row['timer_minutes'] ?? 0);
    $endsAt = (string) ($row['ends_at'] ?? '');
    $status = effectivePollStatus((string) ($row['status'] ?? 'OPEN'), $endsAt);
    $remainingSeconds = remainingSecondsUntil($endsAt);

    return [
        'id' => (string) $row['id'],
        'sessionCode' => (string) $row['session_code'],
        'questionText' => (string) $row['question_text'],
        'type' => $pollType,
        'options' => array_values(array_map(fn($x) => (string) $x, $options)),
        'counts' => $counts,
        'totalVotes' => $totalVotes,
        'targetVotes' => $targetVotes,
        'status' => $status,
        'timerMinutes' => $timerMinutes,
        'endsAt' => $endsAt,
        'remainingSeconds' => $remainingSeconds,
        'myVote' => $myVote,
        'myVotes' => $myVotes,
        'myChoiceCounts' => $myChoiceCounts,
        'myVoteTotal' => $myVoteTotal,
        'maxVotesPerUser' => $maxVotesPerUser,
        'myText' => $myText,
        'responses' => $responses,
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

function createPoll(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $questionText = sanitize($payload['questionText'] ?? '', 200);
    $pollType = strtoupper(sanitize($payload['pollType'] ?? 'CHOICE', 20));
    if ($pollType !== 'TEXT') {
        $pollType = 'CHOICE';
    }
    $targetVotes = max(10, min(1000, toInt($payload['targetVotes'] ?? 50, 50)));
    $maxVotesPerUser = normalizeMaxVotesPerUser($payload['maxVotesPerUser'] ?? POLL_MAX_VOTES_PER_USER, POLL_MAX_VOTES_PER_USER);
    $timerMinutes = normalizeTimerMinutes($payload['timerMinutes'] ?? 0);
    $rawOptions = $payload['options'] ?? [];
    if (!is_array($rawOptions)) {
        throw new RuntimeException('選択肢の形式が不正です');
    }
    $options = [];
    foreach ($rawOptions as $opt) {
        $s = sanitize($opt, 80);
        if ($s !== '') {
            $options[] = $s;
        }
    }
    $options = array_values(array_unique($options));
    if ($questionText === '') {
        throw new RuntimeException('アンケート質問は必須です');
    }
    if ($pollType === 'CHOICE') {
        if (count($options) < 2) {
            throw new RuntimeException('選択肢は2つ以上必要です');
        }
        if (count($options) > 8) {
            throw new RuntimeException('選択肢は最大8つです');
        }
    } else {
        $options = [];
    }
    $open = fetchOne($pdo, 'SELECT id FROM polls WHERE session_code = :session_code AND status = "OPEN" AND (ends_at IS NULL OR ends_at = "" OR ends_at > :now_iso) ORDER BY created_at DESC LIMIT 1', [
        ':session_code' => $sessionCode,
        ':now_iso' => nowIso(),
    ]);
    if ($open) {
        // 既存のOPEN投票がある間は新規作成しない（票が消えたように見える事故を防止）
        return ['ok' => true, 'id' => (string) $open['id'], 'existingOpen' => true];
    }
    $pollMeta = getPollMeta($pdo, $sessionCode);
    if (!$pollMeta['canCreateMore']) {
        throw new RuntimeException('ライブ投票は最大' . (string) $pollMeta['maxPolls'] . '件までです');
    }

    $id = uuidv4();
    $now = nowIso();
    $endsAt = $timerMinutes > 0 ? gmdate('c', time() + ($timerMinutes * 60)) : '';
    execStmt($pdo, 'INSERT INTO polls (id, session_code, question_text, options_json, poll_type, status, target_votes, max_votes_per_user, timer_minutes, ends_at, created_at, updated_at) VALUES (:id, :session_code, :question_text, :options_json, :poll_type, "OPEN", :target_votes, :max_votes_per_user, :timer_minutes, :ends_at, :created_at, :updated_at)', [
        ':id' => $id,
        ':session_code' => $sessionCode,
        ':question_text' => $questionText,
        ':options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
        ':poll_type' => $pollType,
        ':target_votes' => $targetVotes,
        ':max_votes_per_user' => $maxVotesPerUser,
        ':timer_minutes' => $timerMinutes,
        ':ends_at' => $endsAt,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    bumpSessionRevision($pdo, $sessionCode);
    $nextMeta = getPollMeta($pdo, $sessionCode);
    return [
        'ok' => true,
        'id' => $id,
        'existingOpen' => false,
        'pollCount' => $nextMeta['pollCount'],
        'maxPolls' => $nextMeta['maxPolls'],
        'canCreateMore' => $nextMeta['canCreateMore'],
    ];
}

function getPollMeta(PDO $pdo, string $sessionCode): array
{
    $row = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM polls WHERE session_code = :session_code', [
        ':session_code' => $sessionCode,
    ]);
    $pollCount = max(0, toInt($row['c'] ?? 0, 0));
    $maxPolls = VOTE_POLL_LIMIT_PER_SESSION;
    return [
        'pollCount' => $pollCount,
        'maxPolls' => $maxPolls,
        'canCreateMore' => $pollCount < $maxPolls,
    ];
}

function listPollSummaries(PDO $pdo, string $sessionCode): array
{
    $stmt = $pdo->prepare('SELECT id, question_text, status, created_at, updated_at, timer_minutes, ends_at FROM polls WHERE session_code = :session_code ORDER BY created_at DESC, updated_at DESC, rowid DESC');
    $stmt->execute([':session_code' => $sessionCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $endsAt = (string) ($row['ends_at'] ?? '');
        $out[] = [
            'id' => (string) ($row['id'] ?? ''),
            'questionText' => (string) ($row['question_text'] ?? ''),
            'status' => effectivePollStatus((string) ($row['status'] ?? 'OPEN'), $endsAt),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'timerMinutes' => normalizeTimerMinutes($row['timer_minutes'] ?? 0),
            'endsAt' => $endsAt,
        ];
    }
    return $out;
}

function updatePollTarget(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $targetVotes = max(10, min(1000, toInt($payload['targetVotes'] ?? 50, 50)));
    $poll = fetchOne($pdo, 'SELECT id, session_code FROM polls WHERE session_code = :session_code AND status = "OPEN" ORDER BY created_at DESC LIMIT 1', [
        ':session_code' => $sessionCode,
    ]);
    if (!$poll) {
        return ['ok' => true, 'updated' => false, 'poll' => null];
    }
    execStmt($pdo, 'UPDATE polls SET target_votes = :target_votes, updated_at = :updated_at WHERE id = :id', [
        ':target_votes' => $targetVotes,
        ':updated_at' => nowIso(),
        ':id' => (string) $poll['id'],
    ]);
    bumpSessionRevision($pdo, (string) $poll['session_code']);
    $voterToken = sanitize($payload['voterToken'] ?? '', 80);
    return [
        'ok' => true,
        'updated' => true,
        'poll' => getPollInternal($pdo, $sessionCode, $voterToken),
    ];
}
function votePoll(PDO $pdo, array $payload): array
{
    $pollId = sanitize($payload['pollId'] ?? '', 64);
    $voterToken = sanitize($payload['voterToken'] ?? '', 80);
    $choiceIndex = toInt($payload['choiceIndex'] ?? -1, -1);
    $textAnswer = sanitize($payload['textAnswer'] ?? '', 300);
    if ($pollId === '' || $voterToken === '') {
        throw new RuntimeException('不正な投票リクエストです');
    }
    $pdo->beginTransaction();
    try {
        $poll = fetchOne($pdo, 'SELECT id, session_code, options_json, poll_type, status, max_votes_per_user, ends_at FROM polls WHERE id = :id LIMIT 1', [':id' => $pollId]);
        if (!$poll) {
            throw new RuntimeException('アンケートが見つかりません');
        }
        $effectiveStatus = effectivePollStatus((string) ($poll['status'] ?? 'OPEN'), (string) ($poll['ends_at'] ?? ''));
        if ($effectiveStatus !== 'OPEN') {
            if ((string) ($poll['status'] ?? '') === 'OPEN') {
                execStmt($pdo, 'UPDATE polls SET status = "CLOSED", updated_at = :updated_at WHERE id = :id', [
                    ':updated_at' => nowIso(),
                    ':id' => $pollId,
                ]);
                bumpSessionRevision($pdo, (string) $poll['session_code']);
            }
            throw new RuntimeException('このアンケートは終了しています');
        }
        $pollType = strtoupper((string) ($poll['poll_type'] ?? 'CHOICE')) === 'TEXT' ? 'TEXT' : 'CHOICE';
        if ($pollType === 'CHOICE') {
            if ($choiceIndex < 0) {
                throw new RuntimeException('不正な投票リクエストです');
            }
            $options = json_decode((string) ($poll['options_json'] ?? '[]'), true);
            if (!is_array($options) || $choiceIndex >= count($options)) {
                throw new RuntimeException('選択肢が不正です');
            }
            $maxVotesPerUser = normalizeMaxVotesPerUser($poll['max_votes_per_user'] ?? POLL_MAX_VOTES_PER_USER, POLL_MAX_VOTES_PER_USER);
            if ($maxVotesPerUser > 0) {
                $mineCount = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM poll_choice_votes WHERE poll_id = :poll_id AND voter_token = :voter_token', [
                    ':poll_id' => $pollId,
                    ':voter_token' => $voterToken,
                ]);
                $mine = toInt($mineCount['c'] ?? 0, 0);
                if ($mine >= $maxVotesPerUser) {
                    throw new RuntimeException('1人' . $maxVotesPerUser . '票までです');
                }
            }
            execStmt($pdo, 'INSERT INTO poll_choice_votes (poll_id, voter_token, choice_index, created_at) VALUES (:poll_id, :voter_token, :choice_index, :created_at)', [
                ':poll_id' => $pollId,
                ':voter_token' => $voterToken,
                ':choice_index' => $choiceIndex,
                ':created_at' => nowIso(),
            ]);
        } else {
            if ($textAnswer === '') {
                throw new RuntimeException('回答を入力してください');
            }
            $exists = fetchOne($pdo, 'SELECT poll_id FROM poll_text_answers WHERE poll_id = :poll_id AND voter_token = :voter_token LIMIT 1', [
                ':poll_id' => $pollId,
                ':voter_token' => $voterToken,
            ]);
            if ($exists) {
                $pdo->commit();
                return ['ok' => true, 'duplicated' => true];
            }
            execStmt($pdo, 'INSERT INTO poll_text_answers (poll_id, voter_token, answer_text, created_at) VALUES (:poll_id, :voter_token, :answer_text, :created_at)', [
                ':poll_id' => $pollId,
                ':voter_token' => $voterToken,
                ':answer_text' => $textAnswer,
                ':created_at' => nowIso(),
            ]);
        }
        execStmt($pdo, 'UPDATE polls SET updated_at = :updated_at WHERE id = :id', [
            ':updated_at' => nowIso(),
            ':id' => $pollId,
        ]);
        bumpSessionRevision($pdo, (string) $poll['session_code']);
        $pdo->commit();
        return ['ok' => true, 'duplicated' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function closePoll(PDO $pdo, array $payload): array
{
    $pollId = sanitize($payload['pollId'] ?? '', 64);
    if ($pollId === '') {
        throw new RuntimeException('pollId が必要です');
    }
    $poll = fetchOne($pdo, 'SELECT id, session_code FROM polls WHERE id = :id LIMIT 1', [':id' => $pollId]);
    if (!$poll) {
        throw new RuntimeException('アンケートが見つかりません');
    }
    execStmt($pdo, 'UPDATE polls SET status = "CLOSED", updated_at = :updated_at WHERE id = :id', [
        ':updated_at' => nowIso(),
        ':id' => $pollId,
    ]);
    bumpSessionRevision($pdo, (string) $poll['session_code']);
    return ['ok' => true];
}

function clearPoll(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $polls = $pdo->prepare('SELECT id FROM polls WHERE session_code = :session_code');
    $polls->execute([':session_code' => $sessionCode]);
    $rows = $polls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            execStmt($pdo, 'DELETE FROM poll_votes WHERE poll_id = :poll_id', [':poll_id' => (string) $r['id']]);
            execStmt($pdo, 'DELETE FROM poll_choice_votes WHERE poll_id = :poll_id', [':poll_id' => (string) $r['id']]);
            execStmt($pdo, 'DELETE FROM poll_text_answers WHERE poll_id = :poll_id', [':poll_id' => (string) $r['id']]);
        }
        execStmt($pdo, 'DELETE FROM polls WHERE session_code = :session_code', [':session_code' => $sessionCode]);
        bumpSessionRevision($pdo, $sessionCode);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function clearQuestionsData(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $pdo->beginTransaction();
    try {
        execStmt($pdo, 'DELETE FROM votes WHERE entity_id IN (SELECT id FROM questions WHERE session_code = :session_code)', [
            ':session_code' => $sessionCode,
        ]);
        execStmt($pdo, 'DELETE FROM votes WHERE entity_id IN (SELECT id FROM replies WHERE session_code = :session_code)', [
            ':session_code' => $sessionCode,
        ]);
        execStmt($pdo, 'DELETE FROM replies WHERE session_code = :session_code', [
            ':session_code' => $sessionCode,
        ]);
        execStmt($pdo, 'DELETE FROM questions WHERE session_code = :session_code', [
            ':session_code' => $sessionCode,
        ]);
        bumpSessionRevision($pdo, $sessionCode);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getLivePollBoard(PDO $pdo, string $sessionCode, string $voterToken): array
{
    $config = getSessionConfigInternal($pdo, $sessionCode);
    $labels = normalizeMetricLabels($config['metricLabels'] ?? null);
    $activeKinds = [];
    foreach ($labels as $i => $label) {
        $activeKinds[] = 'metric' . ($i + 1);
    }
    $totals = [];
    foreach ($activeKinds as $k) {
        $totals[$k] = 0;
    }
    $stmt = $pdo->prepare('SELECT poll_kind, COUNT(*) AS c FROM live_poll_reactions WHERE session_code = :session_code GROUP BY poll_kind');
    $stmt->execute([':session_code' => $sessionCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $k = normalizeLiveMetricKind((string) ($r['poll_kind'] ?? ''));
        if ($k !== '' && array_key_exists($k, $totals)) {
            $totals[$k] += toInt($r['c'] ?? 0, 0);
        }
    }

    $mine = [];
    foreach ($activeKinds as $k) {
        $mine[$k] = 0;
    }
    if ($voterToken !== '') {
        $m = $pdo->prepare('SELECT poll_kind, COUNT(*) AS c FROM live_poll_reactions WHERE session_code = :session_code AND voter_token = :voter_token GROUP BY poll_kind');
        $m->execute([':session_code' => $sessionCode, ':voter_token' => $voterToken]);
        $mRows = $m->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($mRows as $r) {
            $k = normalizeLiveMetricKind((string) ($r['poll_kind'] ?? ''));
            if ($k !== '' && array_key_exists($k, $mine)) {
                $mine[$k] += toInt($r['c'] ?? 0, 0);
            }
        }
    }

    $topicStmt = $pdo->prepare('SELECT topic_text, COUNT(*) AS c, MAX(created_at) AS last_at
      FROM live_poll_topics
      WHERE session_code = :session_code
      GROUP BY topic_text
      ORDER BY c DESC, last_at DESC
      LIMIT 20');
    $topicStmt->execute([':session_code' => $sessionCode]);
    $topicRows = $topicStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $topics = [];
    $topicTotal = 0;
    foreach ($topicRows as $r) {
        $c = toInt($r['c'] ?? 0, 0);
        $topicTotal += $c;
        $topics[] = [
            'text' => (string) ($r['topic_text'] ?? ''),
            'count' => $c,
            'updatedAt' => (string) ($r['last_at'] ?? ''),
        ];
    }

    $metrics = [];
    foreach ($labels as $i => $label) {
        $kind = 'metric' . ($i + 1);
        $metrics[] = [
            'kind' => $kind,
            'label' => $label,
            'totalHearts' => toInt($totals[$kind] ?? 0, 0),
            'myHearts' => toInt($mine[$kind] ?? 0, 0),
        ];
    }

    return [
        'sessionCode' => $sessionCode,
        'satisfactionHearts' => toInt($totals['metric1'] ?? 0, 0),
        'understandingHearts' => toInt($totals['metric2'] ?? 0, 0),
        'mySatisfactionHearts' => toInt($mine['metric1'] ?? 0, 0),
        'myUnderstandingHearts' => toInt($mine['metric2'] ?? 0, 0),
        'metrics' => $metrics,
        'topics' => $topics,
        'topicTotal' => $topicTotal,
        'revision' => getSessionRevision($pdo, $sessionCode),
    ];
}

function submitLivePoll(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $voterToken = sanitize($payload['voterToken'] ?? '', 80);
    $pollKindRaw = sanitize($payload['pollKind'] ?? '', 20);
    $pollKind = normalizeLiveMetricKind($pollKindRaw);
    $topicText = sanitize($payload['topicText'] ?? '', 300);
    $clientLayout = sanitize($payload['clientLayout'] ?? '', 20);
    if ($voterToken === '') {
        throw new RuntimeException('voterToken が必要です');
    }

    if ($pollKind !== '' && $pollKind !== 'topic') {
        $config = getSessionConfigInternal($pdo, $sessionCode);
        $labels = normalizeMetricLabels($config['metricLabels'] ?? null);
        $allowedKinds = [];
        foreach ($labels as $i => $label) {
            $allowedKinds[] = 'metric' . ($i + 1);
        }
        if (!in_array($pollKind, $allowedKinds, true)) {
            throw new RuntimeException('pollKind が不正です');
        }
        $countMine = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM live_poll_reactions WHERE session_code = :session_code AND poll_kind = :poll_kind AND voter_token = :voter_token', [
            ':session_code' => $sessionCode,
            ':poll_kind' => $pollKind,
            ':voter_token' => $voterToken,
        ]);
        $heartLimit = strtoupper($clientLayout) === 'MOBILE'
            ? LIVE_POLL_MAX_HEARTS_PER_USER_MOBILE
            : LIVE_POLL_MAX_HEARTS_PER_USER;
        $mine = toInt($countMine['c'] ?? 0, 0);
        if ($mine >= $heartLimit) {
            throw new RuntimeException('1人' . (string) $heartLimit . '回までです');
        }
        execStmt($pdo, 'INSERT INTO live_poll_reactions (session_code, poll_kind, voter_token, created_at) VALUES (:session_code, :poll_kind, :voter_token, :created_at)', [
            ':session_code' => $sessionCode,
            ':poll_kind' => $pollKind,
            ':voter_token' => $voterToken,
            ':created_at' => nowIso(),
        ]);
        bumpSessionRevision($pdo, $sessionCode);
        return ['ok' => true];
    }

    if ($pollKind === 'topic') {
        if ($topicText === '') {
            throw new RuntimeException('次回テーマを入力してください');
        }
        execStmt($pdo, 'INSERT INTO live_poll_topics (id, session_code, topic_text, voter_token, created_at) VALUES (:id, :session_code, :topic_text, :voter_token, :created_at)', [
            ':id' => uuidv4(),
            ':session_code' => $sessionCode,
            ':topic_text' => $topicText,
            ':voter_token' => $voterToken,
            ':created_at' => nowIso(),
        ]);
        bumpSessionRevision($pdo, $sessionCode);
        return ['ok' => true];
    }

    throw new RuntimeException('pollKind が不正です');
}

function adjustLivePoll(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $pollKind = normalizeLiveMetricKind(sanitize($payload['pollKind'] ?? '', 20));
    $delta = toInt($payload['delta'] ?? 0, 0);
    if ($pollKind === '') {
        throw new RuntimeException('pollKind が不正です');
    }
    $config = getSessionConfigInternal($pdo, $sessionCode);
    $labels = normalizeMetricLabels($config['metricLabels'] ?? null);
    $allowedKinds = [];
    foreach ($labels as $i => $label) {
        $allowedKinds[] = 'metric' . ($i + 1);
    }
    if (!in_array($pollKind, $allowedKinds, true)) {
        throw new RuntimeException('pollKind が不正です');
    }
    if ($delta === 0) {
        return ['ok' => true, 'changed' => false];
    }

    $steps = min(20, max(1, abs($delta)));
    $now = nowIso();

    $pdo->beginTransaction();
    try {
        if ($delta > 0) {
            for ($i = 0; $i < $steps; $i += 1) {
                execStmt($pdo, 'INSERT INTO live_poll_reactions (session_code, poll_kind, voter_token, created_at) VALUES (:session_code, :poll_kind, :voter_token, :created_at)', [
                    ':session_code' => $sessionCode,
                    ':poll_kind' => $pollKind,
                    ':voter_token' => '__operator__',
                    ':created_at' => $now,
                ]);
            }
            bumpSessionRevision($pdo, $sessionCode);
            $pdo->commit();
            return ['ok' => true, 'changed' => true];
        }

        for ($i = 0; $i < $steps; $i += 1) {
            execStmt($pdo, 'DELETE FROM live_poll_reactions WHERE rowid IN (SELECT rowid FROM live_poll_reactions WHERE session_code = :session_code AND poll_kind = :poll_kind LIMIT 1)', [
                ':session_code' => $sessionCode,
                ':poll_kind' => $pollKind,
            ]);
        }
        bumpSessionRevision($pdo, $sessionCode);
        $pdo->commit();
        return ['ok' => true, 'changed' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function clearLivePoll(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    execStmt($pdo, 'DELETE FROM live_poll_reactions WHERE session_code = :session_code', [':session_code' => $sessionCode]);
    execStmt($pdo, 'DELETE FROM live_poll_topics WHERE session_code = :session_code', [':session_code' => $sessionCode]);
    bumpSessionRevision($pdo, $sessionCode);
    return ['ok' => true];
}

function deleteLivePollTopic(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $topicText = sanitize($payload['topicText'] ?? '', 300);
    if ($topicText === '') {
        throw new RuntimeException('topicText が必要です');
    }

    $stmt = $pdo->prepare('DELETE FROM live_poll_topics WHERE session_code = :session_code AND topic_text = :topic_text');
    $stmt->execute([
        ':session_code' => $sessionCode,
        ':topic_text' => $topicText,
    ]);
    $deleted = (int) $stmt->rowCount();
    if ($deleted <= 0) {
        throw new RuntimeException('対象の次回テーマが見つかりません');
    }

    bumpSessionRevision($pdo, $sessionCode);
    return ['ok' => true, 'deleted' => $deleted];
}

function exportCsv(PDO $pdo, string $sessionCode): void
{
    $q = $pdo->prepare('SELECT id, created_at, display_name, question_text, votes, status, pinned FROM questions WHERE session_code = :session_code ORDER BY created_at ASC');
    $q->execute([':session_code' => $sessionCode]);
    $questions = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $r = $pdo->prepare('SELECT id, question_id, created_at, display_name, reply_text, votes, status FROM replies WHERE session_code = :session_code ORDER BY created_at ASC');
    $r->execute([':session_code' => $sessionCode]);
    $replies = $r->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    $rows[] = ['type', 'session', 'created_at', 'display_name', 'text', 'votes', 'status', 'pinned'];
    foreach ($questions as $row) {
        $rows[] = [
            'question',
            $sessionCode,
            (string) $row['created_at'],
            (string) $row['display_name'],
            (string) $row['question_text'],
            (string) toInt($row['votes'], 0),
            (string) $row['status'],
            toInt($row['pinned'] ?? 0, 0) === 1 ? '1' : '0',
        ];
    }
    foreach ($replies as $row) {
        $rows[] = [
            'reply',
            $sessionCode,
            (string) $row['created_at'],
            (string) $row['display_name'],
            (string) $row['reply_text'],
            (string) toInt($row['votes'], 0),
            (string) $row['status'],
            '',
        ];
    }

    $filename = 'chatapp-' . rawurlencode($sessionCode) . '-' . gmdate('Ymd-His') . '.csv';
    respondCsv($rows, $filename);
}

function isVoteDuplicated(PDO $pdo, string $entityId, string $voterToken): bool
{
    $q = $pdo->prepare('SELECT 1 FROM votes WHERE entity_id = :entity_id AND voter_token = :voter_token LIMIT 1');
    $q->execute([':entity_id' => $entityId, ':voter_token' => $voterToken]);
    return (bool) $q->fetchColumn();
}

function getSessionRevision(PDO $pdo, string $sessionCode): int
{
    $q = $pdo->prepare('SELECT revision FROM session_revisions WHERE session_code = :session_code LIMIT 1');
    $q->execute([':session_code' => $sessionCode]);
    $v = $q->fetchColumn();
    return $v === false ? 0 : toInt($v, 0);
}

function setSessionRevision(PDO $pdo, string $sessionCode, int $revision): void
{
    execStmt($pdo, 'INSERT INTO session_revisions (session_code, revision) VALUES (:session_code, :revision) ON CONFLICT(session_code) DO UPDATE SET revision = :revision', [
        ':session_code' => $sessionCode,
        ':revision' => $revision,
    ]);
}

function bumpSessionRevision(PDO $pdo, string $sessionCode): int
{
    $now = getSessionRevision($pdo, $sessionCode) + 1;
    setSessionRevision($pdo, $sessionCode, $now);
    return $now;
}

function db(): PDO
{
    if (!is_dir(DB_DIR) && !mkdir(DB_DIR, 0775, true) && !is_dir(DB_DIR)) {
        throw new RuntimeException('DBディレクトリを作成できません');
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    return $pdo;
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS questions (
      id TEXT PRIMARY KEY,
      created_at TEXT NOT NULL,
      session_code TEXT NOT NULL,
      display_name TEXT NOT NULL,
      question_text TEXT NOT NULL,
      votes INTEGER NOT NULL DEFAULT 0,
      status TEXT NOT NULL DEFAULT "OPEN",
      author_token TEXT NOT NULL,
      pinned INTEGER NOT NULL DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS replies (
      id TEXT PRIMARY KEY,
      question_id TEXT NOT NULL,
      created_at TEXT NOT NULL,
      session_code TEXT NOT NULL,
      display_name TEXT NOT NULL,
      reply_text TEXT NOT NULL,
      author_token TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT "OPEN",
      votes INTEGER NOT NULL DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS votes (
      entity_id TEXT NOT NULL,
      voter_token TEXT NOT NULL,
      created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS session_revisions (
      session_code TEXT PRIMARY KEY,
      revision INTEGER NOT NULL DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS session_settings (
      session_code TEXT PRIMARY KEY,
      anonymous_only INTEGER NOT NULL DEFAULT 1,
      tip_url TEXT NOT NULL DEFAULT "",
      survey_url TEXT NOT NULL DEFAULT ""
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS hidden_sessions (
      session_code TEXT PRIMARY KEY,
      hidden_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS polls (
      id TEXT PRIMARY KEY,
      session_code TEXT NOT NULL,
      question_text TEXT NOT NULL,
      options_json TEXT NOT NULL,
      poll_type TEXT NOT NULL DEFAULT "CHOICE",
      status TEXT NOT NULL DEFAULT "OPEN",
      target_votes INTEGER NOT NULL DEFAULT 50,
      max_votes_per_user INTEGER NOT NULL DEFAULT 0,
      timer_minutes INTEGER NOT NULL DEFAULT 0,
      ends_at TEXT NOT NULL DEFAULT "",
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS poll_votes (
      poll_id TEXT NOT NULL,
      voter_token TEXT NOT NULL,
      choice_index INTEGER NOT NULL,
      created_at TEXT NOT NULL,
      PRIMARY KEY(poll_id, voter_token)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS poll_choice_votes (
      poll_id TEXT NOT NULL,
      voter_token TEXT NOT NULL,
      choice_index INTEGER NOT NULL,
      created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS poll_text_answers (
      poll_id TEXT NOT NULL,
      voter_token TEXT NOT NULL,
      answer_text TEXT NOT NULL,
      created_at TEXT NOT NULL,
      PRIMARY KEY(poll_id, voter_token)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS live_poll_reactions (
      session_code TEXT NOT NULL,
      poll_kind TEXT NOT NULL,
      voter_token TEXT NOT NULL,
      created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS live_poll_topics (
      id TEXT PRIMARY KEY,
      session_code TEXT NOT NULL,
      topic_text TEXT NOT NULL,
      voter_token TEXT NOT NULL,
      created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS vote_drafts (
      session_code TEXT NOT NULL,
      author_token TEXT NOT NULL,
      question_text TEXT NOT NULL DEFAULT "",
      options_json TEXT NOT NULL DEFAULT "[]",
      target_votes INTEGER NOT NULL DEFAULT 50,
      max_votes_per_user INTEGER NOT NULL DEFAULT 0,
      timer_minutes INTEGER NOT NULL DEFAULT 0,
      updated_at TEXT NOT NULL,
      PRIMARY KEY(session_code, author_token)
    )');

    ensureColumnExists($pdo, 'questions', 'pinned', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($pdo, 'session_settings', 'tip_url', 'TEXT NOT NULL DEFAULT ""');
    ensureColumnExists($pdo, 'session_settings', 'survey_url', 'TEXT NOT NULL DEFAULT ""');
    ensureColumnExists($pdo, 'session_settings', 'metric_labels_json', 'TEXT NOT NULL DEFAULT "[]"');
    ensureColumnExists($pdo, 'session_settings', 'default_request_disabled', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($pdo, 'polls', 'poll_type', 'TEXT NOT NULL DEFAULT "CHOICE"');
    ensureColumnExists($pdo, 'polls', 'target_votes', 'INTEGER NOT NULL DEFAULT 50');
    ensureColumnExists($pdo, 'polls', 'max_votes_per_user', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($pdo, 'polls', 'timer_minutes', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($pdo, 'polls', 'ends_at', 'TEXT NOT NULL DEFAULT ""');
    ensureColumnExists($pdo, 'vote_drafts', 'target_votes', 'INTEGER NOT NULL DEFAULT 50');
    ensureColumnExists($pdo, 'vote_drafts', 'max_votes_per_user', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($pdo, 'vote_drafts', 'timer_minutes', 'INTEGER NOT NULL DEFAULT 0');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_session ON questions(session_code, status, pinned DESC, votes DESC, created_at ASC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_replies_question ON replies(question_id, status, created_at ASC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_replies_session ON replies(session_code, status, created_at ASC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_votes_entity ON votes(entity_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_votes_voter ON votes(voter_token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hidden_sessions_hidden_at ON hidden_sessions(hidden_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_polls_session ON polls(session_code, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_poll_votes_poll ON poll_votes(poll_id, choice_index)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_poll_choice_votes_poll ON poll_choice_votes(poll_id, choice_index)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_poll_choice_votes_voter ON poll_choice_votes(poll_id, voter_token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_poll_text_answers_poll ON poll_text_answers(poll_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_live_poll_reactions_session ON live_poll_reactions(session_code, poll_kind, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_live_poll_topics_session ON live_poll_topics(session_code, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vote_drafts_session ON vote_drafts(session_code, updated_at DESC)');


    // Migration: poll_choice_votes のPRIMARY KEY制約を外して同一選択肢への複数票を許可
    $choiceCols = $pdo->query('PRAGMA table_info(poll_choice_votes)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $choiceHasPk = false;
    foreach ($choiceCols as $col) {
        if (toInt($col['pk'] ?? 0, 0) > 0) {
            $choiceHasPk = true;
            break;
        }
    }
    if ($choiceHasPk) {
        try {
            $pdo->beginTransaction();
            $pdo->exec('CREATE TABLE IF NOT EXISTS poll_choice_votes_new (
              poll_id TEXT NOT NULL,
              voter_token TEXT NOT NULL,
              choice_index INTEGER NOT NULL,
              created_at TEXT NOT NULL
            )');
            $pdo->exec('INSERT INTO poll_choice_votes_new(poll_id, voter_token, choice_index, created_at) SELECT poll_id, voter_token, choice_index, created_at FROM poll_choice_votes');
            $pdo->exec('DROP TABLE poll_choice_votes');
            $pdo->exec('ALTER TABLE poll_choice_votes_new RENAME TO poll_choice_votes');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_poll_choice_votes_poll ON poll_choice_votes(poll_id, choice_index)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_poll_choice_votes_voter ON poll_choice_votes(poll_id, voter_token)');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // Migration: 旧votesテーブルはPRIMARY KEY(entity_id, voter_token)で1人1票制限だったため、
    // 何回でも❤️仕様に向けてPRIMARY KEYなしへ移行する。
    $voteCols = $pdo->query('PRAGMA table_info(votes)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $hasPrimary = false;
    foreach ($voteCols as $col) {
        if (((int) ($col['pk'] ?? 0)) > 0) {
            $hasPrimary = true;
            break;
        }
    }
    if ($hasPrimary) {
        $pdo->beginTransaction();
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS votes_new (
              entity_id TEXT NOT NULL,
              voter_token TEXT NOT NULL,
              created_at TEXT NOT NULL
            )');
            $pdo->exec('INSERT INTO votes_new(entity_id, voter_token, created_at) SELECT entity_id, voter_token, created_at FROM votes');
            $pdo->exec('DROP TABLE votes');
            $pdo->exec('ALTER TABLE votes_new RENAME TO votes');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_votes_entity ON votes(entity_id, created_at DESC)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_votes_voter ON votes(voter_token)');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

function ensureColumnExists(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($rows as $r) {
        if ((string) ($r['name'] ?? '') === $column) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function parseJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSONボディが不正です');
    }
    return $decoded;
}

function sanitize($value, int $maxLen = 200): string
{
    $s = trim((string) $value);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    if (mb_strlen($s) > $maxLen) {
        $s = mb_substr($s, 0, $maxLen);
    }
    return $s;
}

function sanitizeUrl($value): string
{
    $u = sanitize($value, 500);
    if ($u === '') {
        return '';
    }
    if (!preg_match('/^https?:\/\//i', $u)) {
        return '';
    }
    return $u;
}

function changeAdminKey(array $payload): array
{
    $newKey = sanitize($payload['newAdminKey'] ?? '', 200);
    if ($newKey === '') {
        throw new RuntimeException('新しい運営者パスワードを入力してください');
    }
    if (strlen($newKey) < 4) {
        throw new RuntimeException('新しい運営者パスワードは4文字以上で入力してください');
    }

    if (!is_dir(DB_DIR)) {
        @mkdir(DB_DIR, 0775, true);
    }

    $bytes = @file_put_contents(ADMIN_KEY_FILE, $newKey . "
", LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('運営者パスワードの保存に失敗しました');
    }
    @chmod(ADMIN_KEY_FILE, 0644);

    return ['ok' => true, 'changed' => true];
}

function adminKeyDiagnostics(): array
{
    $envRaw = getenv('CHATAPP_ADMIN_KEY');
    $envVal = $envRaw === false ? '' : trim((string) $envRaw);
    $env2 = trim((string) ($_ENV['CHATAPP_ADMIN_KEY'] ?? ''));
    $env3 = trim((string) ($_SERVER['CHATAPP_ADMIN_KEY'] ?? ''));
    $filePath = ADMIN_KEY_FILE;
    $fileAltPath = __DIR__ . '/admin_key.txt';
    $fileExists = is_file($filePath);
    $fileReadable = $fileExists && is_readable($filePath);
    $fileSize = $fileExists ? (int) (@filesize($filePath) ?: 0) : 0;
    $altExists = is_file($fileAltPath);
    $altReadable = $altExists && is_readable($fileAltPath);
    $altSize = $altExists ? (int) (@filesize($fileAltPath) ?: 0) : 0;

    return [
        'configured' => configuredAdminKey() !== '',
        'env' => [
            'getenv' => $envVal !== '',
            '_ENV' => $env2 !== '',
            '_SERVER' => $env3 !== '',
        ],
        'file' => [
            'path' => $filePath,
            'exists' => $fileExists,
            'readable' => $fileReadable,
            'size' => $fileSize,
        ],
        'fileAlt' => [
            'path' => $fileAltPath,
            'exists' => $altExists,
            'readable' => $altReadable,
            'size' => $altSize,
        ],
    ];
}
function configuredAdminKey(): string
{
    $candidates = [
        getenv('CHATAPP_ADMIN_KEY'),
        $_ENV['CHATAPP_ADMIN_KEY'] ?? null,
        $_SERVER['CHATAPP_ADMIN_KEY'] ?? null,
    ];
    foreach ($candidates as $v) {
        if ($v === false || $v === null) {
            continue;
        }
        $s = trim((string) $v);
        if ($s !== '') {
            return $s;
        }
    }

    if (is_file(ADMIN_KEY_FILE)) {
        $raw = @file_get_contents(ADMIN_KEY_FILE);
        if ($raw !== false) {
            $s = trim((string) $raw);
            if ($s !== '') {
                return $s;
            }
        }
    }

    $alt = __DIR__ . '/admin_key.txt';
    if (is_file($alt)) {
        $raw = @file_get_contents($alt);
        if ($raw !== false) {
            $s = trim((string) $raw);
            if ($s !== '') {
                return $s;
            }
        }
    }

    return '';
}

function isAdminPayload(array $payload): bool
{
    $required = configuredAdminKey();
    if ($required === '') {
        return false;
    }
    $given = sanitize($payload['adminKey'] ?? '', 200);
    if ($given === '') {
        return false;
    }
    return hash_equals($required, $given);
}

function requireAdminPayload(array $payload): void
{
    $required = configuredAdminKey();
    if ($required === '') {
        throw new RuntimeException('運営者パスワード未設定です（CHATAPP_ADMIN_KEY / data/admin_key.txt / admin_key.txt）');
    }
    if (!isAdminPayload($payload)) {
        throw new RuntimeException('運営者パスワードが正しくありません');
    }
}

function defaultMetricLabels(): array
{
    return ['満足度', '理解度'];
}

function normalizeMetricLabels($raw): array
{
    $arr = [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $arr = $decoded;
        }
    } elseif (is_array($raw)) {
        $arr = $raw;
    }

    $normalized = [];
    foreach ($arr as $v) {
        $label = sanitize((string) $v, 30);
        if ($label === '') {
            continue;
        }
        $normalized[] = $label;
        if (count($normalized) >= 5) {
            break;
        }
    }
    if (count($normalized) === 0) {
        return defaultMetricLabels();
    }
    return $normalized;
}

function getVoteDraft(PDO $pdo, string $sessionCode, string $authorToken): array
{
    if ($authorToken === '') {
        return ['questionText' => '', 'options' => ['選択肢A', '選択肢B'], 'targetVotes' => 50, 'maxVotesPerUser' => POLL_MAX_VOTES_PER_USER, 'timerMinutes' => 0, 'updatedAt' => ''];
    }
    $row = fetchOne($pdo, 'SELECT question_text, options_json, target_votes, max_votes_per_user, timer_minutes, updated_at FROM vote_drafts WHERE session_code = :session_code AND author_token = :author_token LIMIT 1', [
        ':session_code' => $sessionCode,
        ':author_token' => $authorToken,
    ]);
    if (!$row) {
        return ['questionText' => '', 'options' => ['選択肢A', '選択肢B'], 'targetVotes' => 50, 'maxVotesPerUser' => POLL_MAX_VOTES_PER_USER, 'timerMinutes' => 0, 'updatedAt' => ''];
    }
    $options = json_decode((string)($row['options_json'] ?? '[]'), true);
    if (!is_array($options)) {
        $options = [];
    }
    $cleaned = [];
    foreach ($options as $opt) {
        $v = sanitize((string)$opt, 80);
        if ($v !== '') {
            $cleaned[] = $v;
        }
        if (count($cleaned) >= 6) break;
    }
    if (count($cleaned) < 2) {
        $cleaned = ['選択肢A', '選択肢B'];
    }
    return [
        'questionText' => sanitize((string)($row['question_text'] ?? ''), 200),
        'options' => $cleaned,
        'targetVotes' => max(10, min(1000, toInt($row['target_votes'] ?? 50, 50))),
        'maxVotesPerUser' => normalizeMaxVotesPerUser($row['max_votes_per_user'] ?? POLL_MAX_VOTES_PER_USER, POLL_MAX_VOTES_PER_USER),
        'timerMinutes' => normalizeTimerMinutes($row['timer_minutes'] ?? 0),
        'updatedAt' => (string)($row['updated_at'] ?? ''),
    ];
}

function saveVoteDraft(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? 'default', 40);
    $authorToken = sanitize($payload['authorToken'] ?? '', 80);
    if ($authorToken === '') {
        return ['ok' => true, 'saved' => false];
    }
    $questionText = sanitize($payload['questionText'] ?? '', 200);
    $targetVotes = max(10, min(1000, toInt($payload['targetVotes'] ?? 50, 50)));
    $maxVotesPerUser = normalizeMaxVotesPerUser($payload['maxVotesPerUser'] ?? POLL_MAX_VOTES_PER_USER, POLL_MAX_VOTES_PER_USER);
    $timerMinutes = normalizeTimerMinutes($payload['timerMinutes'] ?? 0);
    $rawOptions = $payload['options'] ?? [];
    if (!is_array($rawOptions)) {
        $rawOptions = [];
    }
    $options = [];
    foreach ($rawOptions as $opt) {
        $v = sanitize((string)$opt, 80);
        if ($v !== '') {
            $options[] = $v;
        }
        if (count($options) >= 6) break;
    }
    if (count($options) < 2) {
        $options = ['選択肢A', '選択肢B'];
    }
    $now = nowIso();
    execStmt($pdo, 'INSERT INTO vote_drafts (session_code, author_token, question_text, options_json, target_votes, max_votes_per_user, timer_minutes, updated_at) VALUES (:session_code, :author_token, :question_text, :options_json, :target_votes, :max_votes_per_user, :timer_minutes, :updated_at) ON CONFLICT(session_code, author_token) DO UPDATE SET question_text = :question_text, options_json = :options_json, target_votes = :target_votes, max_votes_per_user = :max_votes_per_user, timer_minutes = :timer_minutes, updated_at = :updated_at', [
        ':session_code' => $sessionCode,
        ':author_token' => $authorToken,
        ':question_text' => $questionText,
        ':options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
        ':target_votes' => $targetVotes,
        ':max_votes_per_user' => $maxVotesPerUser,
        ':timer_minutes' => $timerMinutes,
        ':updated_at' => $now,
    ]);
    return ['ok' => true, 'saved' => true, 'updatedAt' => $now];
}

function normalizeLiveMetricKind(string $kind): string
{
    $k = strtolower(trim($kind));
    if ($k === 'satisfaction') {
        return 'metric1';
    }
    if ($k === 'understanding') {
        return 'metric2';
    }
    if ($k === 'topic') {
        return 'topic';
    }
    if (preg_match('/^metric([1-5])$/', $k)) {
        return $k;
    }
    return '';
}

function uuidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function nowIso(): string
{
    return gmdate('c');
}

function toInt($value, int $fallback = 0): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    return $fallback;
}

function normalizeMaxVotesPerUser($value, int $fallback = 0): int
{
    $v = toInt($value, $fallback);
    if ($v <= 0) {
        return 0;
    }
    return max(1, min(1000, $v));
}

function normalizeTimerMinutes($value): int
{
    $v = toInt($value, 0);
    if ($v <= 0) {
        return 0;
    }
    return max(1, min(720, $v));
}

function effectivePollStatus(string $status, string $endsAt): string
{
    $s = strtoupper(trim($status));
    if ($s !== 'OPEN') {
        return $s === '' ? 'OPEN' : $s;
    }
    $remaining = remainingSecondsUntil($endsAt);
    if ($remaining !== null && $remaining <= 0) {
        return 'CLOSED';
    }
    return 'OPEN';
}

function remainingSecondsUntil(string $endsAt): ?int
{
    $end = trim($endsAt);
    if ($end === '') {
        return null;
    }
    $ts = strtotime($end);
    if ($ts === false) {
        return null;
    }
    return max(0, $ts - time());
}

function execStmt(PDO $pdo, string $sql, array $params = []): void
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function respond(array $payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function respondCsv(array $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    http_response_code(200);
    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        throw new RuntimeException('CSV出力に失敗しました');
    }
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
}

function listSessions(PDO $pdo): array
{
    $rows = $pdo->query('SELECT session_code FROM session_settings
        UNION SELECT session_code FROM session_revisions
        UNION SELECT session_code FROM questions
        UNION SELECT session_code FROM replies
        UNION SELECT session_code FROM polls
        UNION SELECT session_code FROM live_poll_reactions
        UNION SELECT session_code FROM live_poll_topics')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $hidden = $pdo->query('SELECT session_code FROM hidden_sessions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hiddenMap = [];
    foreach ($hidden as $h) {
        $hiddenMap[(string) $h] = true;
    }

    $out = [];
    foreach ($rows as $v) {
        $code = sanitize((string) $v, 40);
        if ($code === '' || isset($hiddenMap[$code])) {
            continue;
        }
        $out[] = $code;
    }

    if (!in_array('webinar-2026', $out, true)) {
        $out[] = 'webinar-2026';
    }

    $out = array_values(array_unique($out));
    sort($out, SORT_NATURAL);
    return $out;
}

function createSession(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? '', 40);
    if ($sessionCode === '') {
        throw new RuntimeException('セッション名が不正です');
    }

    execStmt($pdo, 'INSERT INTO session_settings (session_code, anonymous_only, tip_url, survey_url, metric_labels_json, default_request_disabled) VALUES (:session_code, 1, "", "", "[]", 1) ON CONFLICT(session_code) DO NOTHING', [
        ':session_code' => $sessionCode,
    ]);
    execStmt($pdo, 'DELETE FROM hidden_sessions WHERE session_code = :session_code', [':session_code' => $sessionCode]);
    setSessionRevision($pdo, $sessionCode, max(1, getSessionRevision($pdo, $sessionCode)));

    return ['ok' => true, 'sessionCode' => $sessionCode, 'sessions' => listSessions($pdo)];
}

function hideSession(PDO $pdo, array $payload): array
{
    $sessionCode = sanitize($payload['sessionCode'] ?? '', 40);
    if ($sessionCode === '') {
        throw new RuntimeException('セッション名が不正です');
    }
    if ($sessionCode === 'webinar-2026') {
        throw new RuntimeException('webinar-2026 は削除できません');
    }

    execStmt($pdo, 'INSERT INTO hidden_sessions (session_code, hidden_at) VALUES (:session_code, :hidden_at) ON CONFLICT(session_code) DO UPDATE SET hidden_at = :hidden_at', [
        ':session_code' => $sessionCode,
        ':hidden_at' => gmdate('c'),
    ]);

    return ['ok' => true, 'sessionCode' => $sessionCode, 'sessions' => listSessions($pdo)];
}

function renameSessionData(PDO $pdo, array $payload): array
{
    $from = sanitize($payload['fromSessionCode'] ?? '', 40);
    $to = sanitize($payload['toSessionCode'] ?? '', 40);

    if ($from === '' || $to === '') {
        throw new RuntimeException('変更前・変更後のセッション名が必要です');
    }
    if ($from === $to) {
        return ['ok' => true, 'fromSessionCode' => $from, 'toSessionCode' => $to, 'moved' => false];
    }

    $pdo->beginTransaction();
    try {
        execStmt($pdo, 'UPDATE questions SET session_code = :to WHERE session_code = :from', [':to' => $to, ':from' => $from]);
        execStmt($pdo, 'UPDATE replies SET session_code = :to WHERE session_code = :from', [':to' => $to, ':from' => $from]);
        execStmt($pdo, 'UPDATE polls SET session_code = :to WHERE session_code = :from', [':to' => $to, ':from' => $from]);
        execStmt($pdo, 'UPDATE live_poll_reactions SET session_code = :to WHERE session_code = :from', [':to' => $to, ':from' => $from]);
        execStmt($pdo, 'UPDATE live_poll_topics SET session_code = :to WHERE session_code = :from', [':to' => $to, ':from' => $from]);
        execStmt($pdo, 'DELETE FROM hidden_sessions WHERE session_code = :session_code', [':session_code' => $to]);

        $fromCfg = fetchOne($pdo, 'SELECT anonymous_only, tip_url, survey_url, metric_labels_json, default_request_disabled FROM session_settings WHERE session_code = :session_code LIMIT 1', [
            ':session_code' => $from,
        ]);
        $toCfg = fetchOne($pdo, 'SELECT anonymous_only, tip_url, survey_url, metric_labels_json, default_request_disabled FROM session_settings WHERE session_code = :session_code LIMIT 1', [
            ':session_code' => $to,
        ]);

        if ($fromCfg) {
            if (!$toCfg) {
                execStmt($pdo, 'INSERT INTO session_settings (session_code, anonymous_only, tip_url, survey_url, metric_labels_json, default_request_disabled) VALUES (:session_code, :anonymous_only, :tip_url, :survey_url, :metric_labels_json, :default_request_disabled)', [
                    ':session_code' => $to,
                    ':anonymous_only' => toInt($fromCfg['anonymous_only'] ?? 1, 1),
                    ':tip_url' => (string) ($fromCfg['tip_url'] ?? ''),
                    ':survey_url' => (string) ($fromCfg['survey_url'] ?? ''),
                    ':metric_labels_json' => (string) ($fromCfg['metric_labels_json'] ?? '[]'),
                    ':default_request_disabled' => toInt($fromCfg['default_request_disabled'] ?? 0, 0),
                ]);
            }
            execStmt($pdo, 'DELETE FROM session_settings WHERE session_code = :session_code', [':session_code' => $from]);
        }

        $fromRev = getSessionRevision($pdo, $from);
        $toRev = getSessionRevision($pdo, $to);
        $merged = max($fromRev, $toRev) + 1;
        setSessionRevision($pdo, $to, $merged);
        execStmt($pdo, 'DELETE FROM session_revisions WHERE session_code = :session_code', [':session_code' => $from]);
        execStmt($pdo, 'DELETE FROM hidden_sessions WHERE session_code = :session_code', [':session_code' => $from]);

        $pdo->commit();
        return ['ok' => true, 'fromSessionCode' => $from, 'toSessionCode' => $to, 'moved' => true, 'revision' => $merged];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
