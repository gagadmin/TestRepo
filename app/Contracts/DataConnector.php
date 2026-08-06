<?php

namespace App\Contracts;

use App\Data\ConnectionResult;
use App\Models\DataSource;

interface DataConnector
{
    public function testConnection(DataSource $dataSource): ConnectionResult;
}
