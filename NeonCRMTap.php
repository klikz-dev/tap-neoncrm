<?php

use GuzzleHttp\Client;
use SingerPhp\SingerTap;
use SingerPhp\Singer;

class NeonCRMTap extends SingerTap
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The base URL of the NeonCRM REST API v2
     * @var string
     */
    const BASE_API_URL = 'https://api.neoncrm.com/v2';

    /**
     * Maximum number of API retries
     * @var integer
     */
    const RETRY_LIMIT = 5;

    /**
     * Delay of retry cycle (seconds)
     * @var integer
     */
    const RETRY_DELAY = 30;

    /**
     * Records per page
     * @var integer
     */
    const RECORDS_PER_PAGE = 200; // 200 is the default value

    /**
     * Organization ID
     * @var string
     */
    private $org_id = '';

    /**
     * API Key
     * @var string
     */
    private $api_key = '';

    /**
     * Singer PHP Types Array
     * @var array
     */
    private $types = [
        'string'    => Singer::TYPE_STRING,
        'integer'   => Singer::TYPE_INTEGER,
        'number'    => Singer::TYPE_NUMBER,
        'boolean'   => Singer::TYPE_BOOLEAN,
        'object'    => Singer::TYPE_OBJECT,
        'array'     => Singer::TYPE_ARRAY,
        'timestamp' => Singer::TYPE_TIMESTAMPTZ
    ];

    /**
     * Tests if the connector is working then writes the results to STDOUT
     */
    public function test()
    {
        $this->org_id  = $this->singer->config->input('org_id');
        $this->api_key = $this->singer->config->input('api_key');

        try {
            $result = $this->request(
                "/accounts", 
                [
                    "userType" => "INDIVIDUAL", 
                    "currentPage" => 0, 
                    "pageSize" => 1
                ]
            );
            $this->singer->writeTestResult(true);
        } catch (Exception $e) {
            $this->singer->writeTestResult(message: $e->getMessage(), thrown: $e);
        }
    }

    /**
     * Gets all schemas/tables and writes the results to STDOUT
     */
    public function discover()
    {
        $this->singer->logger->debug('Starting discover for tap NeonCRM');

        $this->org_id  = $this->singer->config->setting('org_id');
        $this->api_key = $this->singer->config->setting('api_key');

        foreach ($this->singer->config->catalog->streams as $stream) {
            $table = $stream->stream;

            $this->singer->logger->debug("Writing schema for {$table}");

            $columns = [];
            $column_map = $this->table_map[$table]['columns'];
            foreach ($column_map as $colName => $colType) {
                $columns[$colName] = [
                    'type' => $this->types[$colType] ?? Singer::TYPE_STRING
                ];
            }

            $indexes = $this->table_map[$table]['indexes'];
            $this->singer->writeMeta(['unique_keys' => $indexes]);

            $this->singer->writeSchema(
                stream: $table,
                schema: $columns,
                key_properties: $indexes
            );
        }
    }

    /**
     * Gets the record data and writes to STDOUT
     */
    public function tap()
    {
        $this->singer->logger->debug('Starting sync for tap NeonCRM');
        $this->singer->logger->debug("catalog", [$this->singer->config->catalog]);

        $this->org_id  = $this->singer->config->setting('org_id');
        $this->api_key = $this->singer->config->setting('api_key');

        foreach ($this->singer->config->catalog->streams as $stream) {
            $table = $stream->stream;

            // Full Replace
            $this->singer->logger->debug("Writing schema for {$table}");

            $columns = [];
            $column_map = $this->table_map[$table]['columns'];
            foreach ($column_map as $colName => $colType) {
                $columns[$colName] = [
                    'type' => $this->types[$colType] ?? Singer::TYPE_STRING
                ];
            }

            $indexes = $this->table_map[$table]['indexes'];
            $this->singer->writeMeta(['unique_keys' => $indexes]);

            $this->singer->writeSchema(
                stream: $table,
                schema: $columns,
                key_properties: $indexes
            );
            ////

            $this->singer->logger->debug("Starting sync for {$table}");

            $recordIds = $this->fetchAllRecordIdsForTable($table);

            $total_records = 0;
            foreach ($recordIds as $recordId) {
                $result = $this->requestWithRetries("/{$table}/{$recordId}");

                // Account record response has specific structure
                if ( $table == "accounts" ) {
                    $result = $result['individualAccount'] ?? $result['companyAccount'];
                }

                $record = $this->formatRecord($result, $columns);

                $this->singer->writeRecord(
                    stream: $table,
                    record: $record
                );
                $total_records++;
            }

            $this->singer->writeMetric(
                'counter',
                'record_count',
                $total_records,
                [
                    'table' => $table
                ]
            );

            $this->singer->logger->debug("Finished sync for {$table}");
        }
    }

    /**
     * Writes a metadata response with the tables to STDOUT
     */
    public function getTables()
    {
        $tables = array_values(array_keys($this->table_map));
        $this->singer->writeMeta(compact('tables'));
    }

    /**
     * Fetch All Record Ids for a table
     * @param  string   $table    The table name
     * @return array
     */
    public function fetchAllRecordIdsForTable($table)
    {
        // Check if the listing API of this table requires accountId. ex: /accounts/{accountId}/memberships
        $useAccountId = $this->table_map[$table]['useAccountId'];

        if ( $useAccountId ) {
            $accountIds = $this->getAccountIds();

            if ( $table == "accounts" ) {
                $recordIds = $accountIds;
            } else {
                $recordIds = [];
                foreach ($accountIds as $accountId) {
                    $ids = $this->getRecordIds($table, "/accounts/{$accountId}/{$table}");
                    $recordIds = array_merge($recordIds, $ids);
                }
            }
        } else {
            $recordIds = $this->getRecordIds($table, "/{$table}");
        }

        return $recordIds;
    }

    /**
     * Get Account Ids
     * @return array
     */
    public function getAccountIds()
    {
        $individualAccountIds = $this->getRecordIds(
            "accounts", 
            "/accounts", 
            [ 'userType' => 'INDIVIDUAL' ], 
            "accountId"
        );

        $companyAccountIds = $this->getRecordIds(
            "accounts", 
            "/accounts", 
            [ 'userType' => 'COMPANY' ], 
            "accountId"
        );

        return array_merge($individualAccountIds, $companyAccountIds);
    }

    /**
     * Get Record Ids
     * @param  string   $table      The table name
     * @param  string   $uri        The API URI
     * @param  array    $params     The array of API query params
     * @param  string   $idKey      The variable name of the record id
     * @return array
     */
    public function getRecordIds($table, $uri, $params = [], $idKey = 'id')
    {
        $pagination = $this->table_map[$table]['pagination'];

        $recordIds = [];
        if ( $pagination ) {
            $currentPage = 0;
            do {
                $result = $this->requestWithRetries(
                    $uri, 
                    array_merge($params, [
                        'currentPage' => $currentPage,
                        'pageSize' => self::RECORDS_PER_PAGE
                    ])
                );

                if ( isset($result[$table]) && is_array($result[$table]) ) {
                    foreach ($result[$table] as $record) {
                        $recordIds[] = $record[$idKey];
                    }
                }

                $currentPage++;
            } while ($currentPage < $result['pagination']['totalPages']);
        } else {
            $records = $this->requestWithRetries($uri, $params);

            foreach ($records as $record) {
                $recordIds[] = $record[$idKey];
            }
        }

        return array_unique($recordIds);
    }

    /**
     * Format records to match table columns
     * @param array   $record           The response array
     * @param array   $columns          The record model
     * @return array
     */
    public function formatRecord($record, $columns) {
        // Remove unmapped fields from the response.
        $record = array_filter($record, function($key) use($columns) {
            return array_key_exists($key, $columns);
        }, ARRAY_FILTER_USE_KEY);

        // column mapping for missing response fields.
        foreach ($columns as $colKey => $colVal) {
            if (!array_key_exists($colKey, $record)) {
                $record[$colKey] = null;
            }
        }

        return $record;
    }

    /**
     * Make a request with retry logic
     * @param string    $uri        The API URI
     * @param  array    $params     The array of API query params
     * @return array    The API response array
     */
    public function requestWithRetries($uri, $params = [])
    {
        $attempts = 1;
        while (true) {
            try {
                return $this->request($uri, $params);
            } catch (Exception $e) {
                if ($attempts > self::RETRY_LIMIT) {
                    throw $e;
                }
                $this->singer->logger->debug("NeonCRM API request failed. Retrying. Attempt {$attempts} of " . self::RETRY_LIMIT . " in " . self::RETRY_DELAY . " seconds.");
                $attempts++;
                sleep(self::RETRY_DELAY);
            }
        }
    }

    /**
     * Make a request to the NeonCRM REST API
     * @param  string   $uri        The API URI
     * @param  array    $params     The array of API query params
     * @return array    The API response array
     */
    public function request($uri, $params = [])
    {
        $client = new Client([
            'auth' => [$this->org_id, $this->api_key],
            'http_errors' => false
        ]);

        $response = $client->get(self::BASE_API_URL . $uri, [
            'query' => $params
        ]);

        $status_code = $response->getStatusCode();
        switch ($status_code) {
            case 200:
                return (array) json_decode($response->getBody()->getContents(), true);
            case 401:
                throw new Exception("API credentials are invalid");
            case 403:
                throw new Exception("API Key doesn't have permission to the requested resource. uri: {$uri}");
            case 404:
                throw new Exception("API endpoint not found. uri: {$uri}");
            case 429:
                $this->singer->logger->debug("Too many requests. Retry in " . self::RETRY_DELAY . " seconds.");
                sleep(self::RETRY_DELAY);
                return $this->request($uri, $params);
            default:
                throw new Exception("Server side error occurred. uri: {$uri}, code: {$status_code}");
        }
    }

    /**
     * Array of table data.
     * @var array
     */
    private $table_map = [
        'accounts' => [
            'pagination' => true,
            'useAccountId' => true,
            'columns' => [
                'accountId'               => 'string',
                'company'                 => 'object',
                'facebookPage'            => 'string',
                'noSolicitation'          => 'boolean',
                'login'                   => 'object',
                'twitterPage'             => 'string',
                'individualTypes'         => 'array',
                'url'                     => 'string',
                'timestamps'              => 'object',
                'consent'                 => 'object',
                'accountCustomFields'     => 'array',
                'source'                  => 'object',
                'primaryContact'          => 'object',
                'sendSystemEmail'         => 'boolean',
                'origin'                  => 'object',
                'name'                    => 'string',
                'primaryContactAccountId' => 'string',
                'companyTypes'            => 'string',
                'shippingAddresses'       => 'array',
                'accountType'             => 'string'
            ],
            'indexes' => [
                'accountId'
            ],
        ],
        'donations' => [
            'pagination' => true,
            'useAccountId' => true,
            'columns' => [
                'batchNumber'           => 'string',
                'donorName'             => 'string',
                'id'                    => 'string',
                'accountId'             => 'string',
                'date'                  => 'timestamp',
                'sendAcknowledgeEmail'  => 'boolean',
                'amount'                => 'number',
                'anonymousType'         => 'string',
                'sendAcknowledgeLetter' => 'boolean',
                'donorCoveredFeeFlag'   => 'boolean',
                'purpose'               => 'object',
                'source'                => 'object',
                'campaign'              => 'object',
                'donorCoveredFee'       => 'number',
                'solicitationMethod'    => 'object',
                'acknowledgee'          => 'object',
                'fund'                  => 'object',
                'payLater'              => 'boolean',
                'payments'              => 'array',
                'timestamps'            => 'object',
                'tribute'               => 'object',
                'donationCustomFields'  => 'array',
                'fundraiserAccountId'   => 'string',
                'status'                => 'string',
                'craInfo'               => 'object'
            ],
            'indexes' => [
                'id',
                'accountId'
            ]
        ],
        'eventRegistrations' => [
            'pagination' => true,
            'useAccountId' => true,
            'columns' => [
                'id'                     => 'string',
                'payments'               => 'array',
                'donorCoveredFeeFlag'    => 'boolean',
                'eventId'                => 'string',
                'donorCoveredFee'        => 'number',
                'registrationDateTime'   => 'timestamp',
                'couponCode'             => 'string',
                'payLater'               => 'boolean',
                'craInfo'                => 'object',
                'taxDeductibleAmount'    => 'number',
                'sendSystemEmail'        => 'boolean',
                'registrationAmount'     => 'number',
                'ignoreCapacity'         => 'boolean',
                'registrantAccountId'    => 'string',
                'fundraiserAccountId'    => 'string',
                'registrantCustomFields' => 'array',
                'tickets'                => 'array',
                'source'                 => 'object'
            ],
            'indexes' => [
                'id',
                'eventId'
            ]
        ],
        'memberships' => [
            'pagination' => true,
            'useAccountId' => true,
            'columns' => [
                'id'                     => 'string',
                'subMembers'             => 'array',
                'parentId'               => 'string',
                'payLater'               => 'boolean',
                'accountId'              => 'string',
                'payments'               => 'array',
                'donorCoveredFeeFlag'    => 'boolean',
                'membershipLevel'        => 'object',
                'donorCoveredFee'        => 'number',
                'membershipTerm'         => 'object',
                'autoRenewal'            => 'boolean',
                'source'                 => 'object',
                'changeType'             => 'string',
                'termUnit'               => 'string',
                'termDuration'           => 'integer',
                'enrollType'             => 'string',
                'transactionDate'        => 'string',
                'termStartDate'          => 'string',
                'termEndDate'            => 'string',
                'fee'                    => 'number',
                'couponCode'             => 'string',
                'sendAcknowledgeEmail'   => 'boolean',
                'status'                 => 'string',
                'complimentary'          => 'integer',
                'membershipCustomFields' => 'array',
                'craInfo'                => 'object',
                'timestamps'             => 'object'
            ],
            'indexes' => [
                'id',
                'parentId',
                'accountId'
            ]
        ],
        'orders' => [
            'pagination' => true,
            'useAccountId' => true,
            'columns' => [
                'donations'           => 'array',
                'id'                  => 'string',
                'eventRegistrations'  => 'array',
                'orderDate'           => 'string',
                'accountId'           => 'string',
                'products'            => 'array',
                'memberships'         => 'array',
                'totalCharge'         => 'number',
                'needShipping'        => 'boolean',
                'subTotal'            => 'number',
                'shipping'            => 'object',
                'tax'                 => 'number',
                'discounts'           => 'array',
                'totalDiscount'       => 'number',
                'donorCoveredFeeFlag' => 'boolean',
                'shippingHandlingFee' => 'number',
                'donorCoveredFee'     => 'number',
                'status'              => 'string',
                'payLater'            => 'boolean',
                'timestamps'          => 'object',
                'payments'            => 'array'
            ],
            'indexes' => [
                'id',
                'accountId'
            ]
        ],
        'pledges' => [
            'pagination' => true,
            'useAccountId' => true,
            'columns' => [
                'donorName'            => 'string',
                'id'                   => 'string',
                'matchedDonationId'    => 'string',
                'accountId'            => 'string',
                'date'                 => 'string',
                'amount'               => 'number',
                'anonymousType'        => 'string',
                'purpose'              => 'object',
                'source'               => 'object',
                'campaign'             => 'object',
                'solicitationMethod'   => 'object',
                'acknowledgee'         => 'object',
                'fund'                 => 'object',
                'timestamps'           => 'object',
                'tribute'              => 'object',
                'donationCustomFields' => 'array',
                'fundraiserAccountId'  => 'string'
            ],
            'indexes' => [
                'id',
                'accountId'
            ]
        ],
        'campaigns' => [
            'pagination' => false,
            'useAccountId' => false,
            'columns' => [
                'id'                => 'string',
                'name'              => 'string',
                'code'              => 'string',
                'startDate'         => 'timestamp',
                'endDate'           => 'timestamp',
                'fund'              => 'object',
                'purpose'           => 'object',
                'parentCampaign'    => 'object',
                'pageContent'       => 'string',
                'status'            => 'string',
                'goal'              => 'number',
                'campaignPageUrl'   => 'string',
                'donationFormUrl'   => 'string',
                'statistics'        => 'object',
                'socialFundraising' => 'object',
                'craInfo'           => 'object'
            ],
            'indexes' => [
                'id'
            ]
        ],
        'events' => [
            'pagination' => true,
            'useAccountId' => false,
            'columns' => [
                'id'                          => 'string',
                'name'                        => 'string',
                'summary'                     => 'string',
                'code'                        => 'string',
                'maximumAttendees'            => 'integer',
                'category'                    => 'object',
                'topic'                       => 'object',
                'campaign'                    => 'object',
                'publishEvent'                => 'boolean',
                'enableEventRegistrationForm' => 'boolean',
                'archived'                    => 'boolean',
                'enableWaitListing'           => 'boolean',
                'createAccountsforAttendees'  => 'boolean',
                'eventDescription'            => 'string',
                'eventDates'                  => 'object',
                'financialSettings'           => 'object',
                'location'                    => 'object'
            ],
            'indexes' => [
                'id'
            ]
        ]
    ];
}
