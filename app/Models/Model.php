<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

abstract class Model
{
    public function __construct(protected readonly Database $db) {}
}
