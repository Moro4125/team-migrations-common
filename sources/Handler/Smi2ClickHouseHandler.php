<?php
/**
 * Class Smi2ClickHouseHandler
 */

namespace Moro\Migration\Handler;

use ClickHouseDB\Client;

/**
 * Class Smi2ClickHouseHandler
 * @package Moro\Migration\Handler
 */
class Smi2ClickHouseHandler extends AbstractClickHouseHandler
{
    protected $_client;

    /**
     * @var string  The name of service for save migration information.
     */
    protected $_serviceName = 'team-migrations.smi2-clickhouse';

    /**
     * @param Client $client
     * @param null|string $serviceName
     */
    public function __construct(Client $client, ?string $serviceName = null)
    {
        $this->_client = $client;
        parent::__construct($serviceName);
    }

    /**
     * @param string $sql
     */
    protected function _executeSql($sql)
    {
        $this->_client->write($sql);
    }

    /**
     * @param string $sql
     * @param array|null $params
     * @return array
     */
    protected function _callQuery(string $sql, array $params = null): array
    {
        $results = [];
        $bindings = [];
        $names = 'ABCDEFJHIGKLMNOPQRSTUVWXYZ';

        foreach (array_values($params ?? []) as $index => $param) {
            $char = substr($names, $index, 1);
            $key = ':'.$char;
            $sql = substr_replace($sql, $key, strpos($sql, '?'), 1);
            $bindings[$char] = $param;
        }

        $statement = $this->_client->select($sql, $bindings);

        while ($record = $statement->fetchRow()) {
            $results[] = $record;
        }

        return $results;
    }

    /**
     * @param string $sql
     * @param array|null $params
     * @return int
     */
    protected function _callUpdate(string $sql, array $params = null): int
    {
        $bindings = [];
        $names = 'ABCDEFJHIGKLMNOPQRSTUVWXYZ';

        foreach (array_values($params ?? []) as $index => $param) {
            if ($param !== null) {
                $char = substr($names, $index, 1);
                $key = ':' . $char;
                $sql = substr_replace($sql, $key, strpos($sql, '?'), 1);
                $bindings[$char] = $param;
            } else {
                $sql = substr_replace($sql, 'NULL', strpos($sql, '?'), 1);
            }
        }

        $this->_client->transport()->write($sql, $bindings);

        return 1;
    }
}