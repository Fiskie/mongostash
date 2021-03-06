<?php

namespace MongoStash;

use Stash\Driver\AbstractDriver;
use Stash\Exception\InvalidArgumentException;


/**
 * Supports the classic Mongo PHP driver, the MongoClient class.
 * Stores persistent cache to MongoDB, which can be a
 * good option for a distributed cache.
 *
 * @package MongoStash
 */
class MongoDB extends AbstractDriver {
    /**
     * @var \MongoCollection|\MongoDB\Collection
     */
    private $collection;

    /**
     * @param array $key
     * @return string
     */
    private static function mapKey($key)
    {
        return implode('/', $key);
    }

    /**
     * {@inheritdoc}
     */
    public function getData($key)
    {
        $doc = $this->collection->findOne(['_id' => self::mapKey($key)]);
        if ($doc) {
            return [
                'data' => unserialize($doc['data']),
                'expiration' => $doc['expiration']
            ];
        } else {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeData($key, $data, $expiration)
    {
        if ($this->collection instanceof \MongoDB\Collection) {
            $id = self::mapKey($key);

            try {
                $this->collection->replaceOne(['_id' => $id],[
                    '_id' => $id,
                    'data' => serialize($data),
                    'expiration' => $expiration
                ], ['upsert' => true]);
            } catch (\MongoDB\Driver\Exception\BulkWriteException $ignored) {
                // As of right now, BulkWriteException can be thrown by
                // replaceOne in high-throughput environments where race
                // conditions can occur
            }
        } else {
            try {
                $this->collection->save([
                    '_id' => self::mapKey($key),
                    'data' => serialize($data),
                    'expiration' => $expiration
                ]);
            } catch (\MongoDuplicateKeyException $ignored) {
                // Because it's Mongo, we might have had this problem because
                // of a cache stampede
            }
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear($key = null)
    {
        if (!$key) {
            $this->collection->drop();
            return true;
        }

        $preg = "^" . preg_quote(self::mapKey($key));

        if ($this->collection instanceof \MongoDB\Collection) {
            $this->collection->deleteMany([
                '_id' => new \MongoDB\BSON\Regex($preg, '')
            ]);
        } else {
            $this->collection->remove([
                '_id' => new \MongoRegex($preg)
            ], ['multiple' => true]);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function purge()
    {
        if ($this->collection instanceof \MongoDB\Collection) {
            $this->collection->deleteMany([
                'expiration' => ['$lte' => time()]
            ]);
        } else {
            $this->collection->remove([
                'expiration' => ['$lte' => time()]
            ], ['multiple' => true]);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultOptions()
    {
        return array(
            'mongo' => null,
            'database' => null,
            'collection' => 'stash.store'
        );
    }

    /**
     * mongo - A MongoClient/Mongo/MongoDB instance. Required.
     *
     * @param array $options
     * @throws InvalidArgumentException
     */
    public function setOptions(array $options = array())
    {
        $options += $this->getDefaultOptions();

        $client = $options['mongo'];

        if (!($client instanceof \MongoClient ||
            $client instanceof \Mongo ||
            $client instanceof \MongoDB\Client)) {
            $err = 'MongoClient, Mongo or MongoDB\Client instance required';
            throw new \InvalidArgumentException($err);
        }

        $db = $options['database'];
        $collection = $options['collection'];

        if ($db === null)
            throw new \InvalidArgumentException("A database is required.");

        $this->collection = $client->selectCollection($db, $collection);
    }

    /**
     * {@inheritdoc}
     */
    public static function isAvailable()
    {
        return class_exists('\MongoDB\Client', false) ||
            class_exists('\MongoClient', false);
    }

    /**
     * {@inheritdoc}
     */
    public function isPersistent()
    {
        return true;
    }
}