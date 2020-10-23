<?php

namespace EtlPipeliner\Laravel;

use EtlPipeliner\AbstractExtractor;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SqlServerConnection;

class DbExtractor extends AbstractExtractor
{
    /** @var \Illuminate\Database\Connection */
    protected $connection;
    /** @var \Illuminate\Database\Query\Builder */
    protected $query;
    /** @var array */
    protected $uniqueColumns = ['id'];
    /** @var string */
    protected $updateColumn = 'updated_at';


    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->query = $this->connection->query();
    }

    public function query()
    {
        return $this->query;
    }

    public function uniqueColumns(array $uniqueColumns)
    {
        $this->uniqueColumns = $uniqueColumns;
    }

    public function getUniqueColumns()
    {
        return $this->uniqueColumns;
    }

    public function createFullHashedIndex()
    {
        // md5 hash of all unique columns concat'd
    }

    public function getChunkSize()
    {
        return 1000;
    }

    public function extract($incremental = false): \Generator
    {
        $hashedQuery = $this->createHashedQuery();
        $lastId = null;

        do {
            $clone = clone $hashedQuery;
            $results = $clone->forPageAfterId(1000, $lastId, '_hash')->get();
            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            foreach ($results as $result) {
                yield (array)$result;
            }

            $lastId = $results->last()->_hash;

            unset($results);
        } while ($countResults == 1000);
    }

    protected function createHashedQuery()
    {
        $query = clone $this->query;

        if ($this->connection instanceof MySqlConnection) {
            $query->addSelect(
                $this->connection->raw(
                    'CONCAT_WS("|", '
                    . collect($this->getUniqueColumns())->implode(', ')
                    . ') as `_hash`'
                )
            );
        } elseif ($this->connection instanceof SqlServerConnection) {
            $query->addSelect(
                $this->connection->raw(
                    '('
                    . collect($this->getUniqueColumns())
                        ->map(function ($uniqueColumn) {
                            return 'CAST(' . $this->connection->getQueryGrammar()->wrap($uniqueColumn) . ' as varchar)';
                        })
                        ->implode(" + '|' + ")
                    . ') as [_hash]'
                )
            );
        } elseif ($this->connection instanceof PostgresConnection) {
            $query->addSelect(
                $this->connection->raw(
                    "CONCAT_WS('|', "
                    . collect($this->getUniqueColumns())->implode(',')
                    . ') AS "_hash"'
                )
            );
        } else {
            throw new \RuntimeException('Currently only MySQL, Postgres, and SqlServer are supported inside the ' . __CLASS__);
        }

        $hashedQuery = $this->connection->query();
        $hashedQuery->fromSub($query, 'source');

        return $hashedQuery;
    }
}
