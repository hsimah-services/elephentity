<?php
declare(strict_types=1);
namespace Eleph\SQLite\Manifest;

use Eleph\SQLite\Sql\{Column, EdgePlacement, Index, TableSchema};
use Eleph\Runtime\Storage\RelationKind;

return new StorageManifest(
    tables: [
        'Page' => new TableSchema(
            'page',
            [
                'id' => new Column('id', 'INTEGER', false, true, null),
                'title' => new Column('title', 'TEXT', false, false, null),
                'slug' => new Column('slug', 'TEXT', false, false, null),
                'content' => new Column('content', 'TEXT', false, false, null),
                'excerpt' => new Column('excerpt', 'TEXT', false, false, null),
                'status' => new Column('status', 'TEXT', false, false, null),
                'published_at' => new Column('published_at', 'TEXT', true, false, null),
                'enquiry_url' => new Column('enquiry_url', 'TEXT', true, false, null),
                'active' => new Column('active', 'INTEGER', false, false, null),
                'zero' => new Column('zero', 'INTEGER', false, false, null),
                'disabled' => new Column('disabled', 'INTEGER', false, false, null),
                'metadata' => new Column('metadata', 'TEXT', false, false, null),
                'created_at' => new Column('created_at', 'TEXT', false, false, null),
                'rank' => new Column('rank', 'INTEGER', false, false, null),
                'amount' => new Column('amount', 'TEXT', false, false, null),
            ],
            [

            ],
            'id',
        ),
        'User' => new TableSchema(
            'user',
            [
                'id' => new Column('id', 'INTEGER', false, true, null),
                'username' => new Column('username', 'TEXT', false, false, null),
                'role' => new Column('role', 'INTEGER', false, false, null),
                'active' => new Column('active', 'INTEGER', false, false, null),
            ],
            [

            ],
            'id',
        ),
    ],
    placements: [

    ],
    columns: [
        'Page' => ['title' => 'title', 'slug' => 'slug', 'content' => 'content', 'excerpt' => 'excerpt', 'status' => 'status', 'publishedAt' => 'published_at', 'enquiryUrl' => 'enquiry_url', 'active' => 'active', 'zero' => 'zero', 'disabled' => 'disabled', 'metadata' => 'metadata', 'createdAt' => 'created_at', 'rank' => 'rank', 'amount' => 'amount'],
        'User' => ['username' => 'username', 'role' => 'role', 'active' => 'active'],
    ],
    joinTables: [

    ],
);
