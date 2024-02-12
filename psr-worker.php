<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

function createDbConnection(): \PDO
{
    $dsn = sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        getenv('DB_CONNECTION') ?: 'mysql',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: 3306,
        getenv('DB_NAME') ?: 'rinha',
    );

    if (getenv('DB_CONNECTION') === 'mysql') {
        $dsn .= sprintf(";charset=%s", getenv('DB_CHARSET') ?: 'utf8mb4');
    }

    $username = getenv('DB_USER') ?: 'rinha';
    $password = getenv('DB_PASSWORD') ?: 'rinha';

    $connection = new \PDO($dsn, $username, $password, [
        \PDO::ATTR_PERSISTENT => true
    ]);
    $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $connection;
}

function getExtrato(ServerRequestInterface $request, \PDO $dbConnection): ResponseInterface
{
    $pathParts = explode('/', $request->getUri()->getPath());
    $customerId = $pathParts[2];

    $now = new \DateTime();

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);
    if (! $customerResult) {
        return new Response(404);
    }

    $customerLastTransactionsQuery = "
        SELECT valor, tipo, descricao, realizada_em
        FROM transacoes
        WHERE cliente_id = :customerId
        ORDER BY id DESC
        LIMIT 10
    ";

    $customerLastTransactionsStatement = $dbConnection->prepare($customerLastTransactionsQuery);
    $customerLastTransactionsStatement->bindParam(':customerId', $customerId);
    $customerLastTransactionsStatement->execute();

    $customerLastTransactionsResult = $customerLastTransactionsStatement->fetchAll(\PDO::FETCH_ASSOC);

    return new Response(
        200,
        [
            'Content-Type' => 'application/json'
        ],
        json_encode([
            'saldo' => [
                'total' => intval($customerResult['saldo']),
                'data_extrato' => $now->format(\DateTime::ATOM),
                'limite' => intval($customerResult['limite']),
            ],
            'ultimas_transacoes' => $customerLastTransactionsResult
                ? array_map(function ($row) {
                    return [
                        'valor' => intval($row['valor']),
                        'tipo' => $row['tipo'],
                        'descricao' => $row['descricao'],
                        'realizada_em' => (new \DateTime($row['realizada_em']))->format(\DateTime::ATOM),
                    ];
                }, $customerLastTransactionsResult)
                : []
        ])
    );
};

function createTransaction(ServerRequestInterface $request, \PDO $dbConnection): ResponseInterface
{
    $requestData = json_decode((string) $request->getBody(), true);
    if (! $requestData) {
        return new Response(404);
    }

    $amount = $requestData['valor'];
    $type = $requestData['tipo'];
    $description = $requestData['descricao'];

    if (empty($amount) || empty($type) || empty($description)) {
        return new Response(422);
    }

    if ($amount <= 0 || ! is_int($amount)) {
        return new Response(422);
    }

    if (!in_array($type, ['c', 'd'])) {
        return new Response(422);
    }

    $descriptionLength = strlen($description);
    if ($descriptionLength < 0 || $descriptionLength > 10) {
        return new Response(422);
    }

    $pathParts = explode('/', $request->getUri()->getPath());
    $customerId = $pathParts[2];

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);
    if (! $customerResult) {
        return new Response(404);
    }

    $createTransactionQuery = "
        CALL create_transaction(:customerId, :amount, :type, :description)
    ";

    $createTransactionStatement = $dbConnection->prepare($createTransactionQuery);
    $createTransactionStatement->bindParam(':customerId', $customerId, \PDO::PARAM_INT);
    $createTransactionStatement->bindParam(':amount', $amount, \PDO::PARAM_INT);
    $createTransactionStatement->bindParam(':type', $type, \PDO::PARAM_STR);
    $createTransactionStatement->bindParam(':description', $description, \PDO::PARAM_STR);

    try {
        $createTransactionStatement->execute();
    } catch (\PDOException $e) {
        return new Response(422);
    }

    $customerQuery = "
        SELECT *
        FROM clientes
        WHERE id = :customerId
    ";

    $customerStatement = $dbConnection->prepare($customerQuery);
    $customerStatement->bindParam(':customerId', $customerId);
    $customerStatement->execute();

    $customerResult = $customerStatement->fetch(\PDO::FETCH_ASSOC);

    return new Response(
        200,
        [
            'Content-Type' => 'application/json'
        ],
        json_encode([
            'limite' => intval($customerResult['limite']),
            'saldo' => intval($customerResult['saldo'])
        ])
    );
};

$worker = Worker::create();
$psrFactory = new Psr17Factory();

$psr7 = new PSR7Worker($worker, $psrFactory, $psrFactory, $psrFactory);

$dbConnection = createDbConnection();

while (true) {
    try {
        $request = $psr7->waitRequest();
    } catch (\Throwable $e) {
        $psr7->respond(new Response(400));

        continue;
    }

    try {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'POST' && preg_match('/\/clientes\/\d+\/transacoes/', $path)) {
            $psr7->respond(createTransaction($request, $dbConnection));

            continue;
        } elseif ($method === 'GET' && preg_match('/\/clientes\/\d+\/extrato/', $path)) {
            $psr7->respond(getExtrato($request, $dbConnection));

            continue;
        } else {
            $psr7->respond(new Response(400));

            continue;
        }
    } catch (\Throwable $e) {
        $psr7->respond(new Response(500));

        $worker->error((string) $e);
    }
}
