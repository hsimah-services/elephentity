<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

use Eleph\Runtime\Storage\RelationKind;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\EdgePlacement;
use Eleph\WordPress\Sql\Index;
use Eleph\WordPress\Sql\TableSchema;

/**
 * The compiled physical schema.
 *
 * Loaded at boot and handed to the adaptor as-is. Nothing here is worked out
 * per request; the spec decided all of it.
 */
return new StorageManifest(
    tables: [
        'Author' => new TableSchema(
            'phe_author',
            [
                'id' => new Column('id', 'BIGINT UNSIGNED', false, true, null),
                'name' => new Column('name', 'VARCHAR(120)', false, false, null),
            ],
            [

            ],
            'id',
        ),
        'Comment' => new TableSchema(
            'phe_comment',
            [
                'id' => new Column('id', 'BIGINT UNSIGNED', false, true, null),
                'body' => new Column('body', 'LONGTEXT', false, false, null),
                'author_id' => new Column('author_id', 'BIGINT UNSIGNED', true, false, null),
                'post_id' => new Column('post_id', 'BIGINT UNSIGNED', true, false, null),
            ],
            [
                'phe_comment_author_id_idx' => new Index('phe_comment_author_id_idx', ['author_id'], false),
                'phe_comment_post_id_idx' => new Index('phe_comment_post_id_idx', ['post_id'], false),
            ],
            'id',
        ),
        'Post' => new TableSchema(
            'phe_post',
            [
                'id' => new Column('id', 'BIGINT UNSIGNED', false, true, null),
                'created_at' => new Column('created_at', 'DATETIME', false, false, null),
                'updated_at' => new Column('updated_at', 'DATETIME', false, false, null),
                'post_id' => new Column('post_id', 'BIGINT', true, false, null),
                'slug' => new Column('slug', 'VARCHAR(120)', false, false, null),
                'title' => new Column('title', 'VARCHAR(200)', false, false, null),
                'price' => new Column('price', 'BIGINT', true, false, null),
                'status' => new Column('status', 'VARCHAR(9)', false, false, null),
                'visibility' => new Column('visibility', 'VARCHAR(7)', false, false, null),
                'published_at' => new Column('published_at', 'DATETIME', true, false, null),
            ],
            [
                'phe_post_post_id_uniq' => new Index('phe_post_post_id_uniq', ['post_id'], true),
                'phe_post_title_idx' => new Index('phe_post_title_idx', ['title'], false),
            ],
            'id',
        ),
        'Tag' => new TableSchema(
            'phe_tag',
            [
                'id' => new Column('id', 'BIGINT UNSIGNED', false, true, null),
                'label' => new Column('label', 'VARCHAR(255)', false, false, null),
            ],
            [
                'phe_tag_label_uniq' => new Index('phe_tag_label_uniq', ['label'], true),
            ],
            'id',
        ),
    ],
    placements: [
        'Comment.author' => new EdgePlacement(
            'Comment',
            'author',
            'Author',
            RelationKind::ManyToOne,
            'phe_comment',
            'author_id',
            null,
            'phe_author',
        ),
        'Post.comments' => new EdgePlacement(
            'Post',
            'comments',
            'Comment',
            RelationKind::OneToMany,
            'phe_comment',
            'post_id',
            null,
            'phe_comment',
        ),
        'Post.tags' => new EdgePlacement(
            'Post',
            'tags',
            'Tag',
            RelationKind::ManyToMany,
            'phe_post_tags',
            'post_id',
            'tag_id',
            'phe_tag',
        ),
    ],
    columns: [
        'Author' => ['name' => 'name'],
        'Comment' => ['body' => 'body'],
        'Post' => ['createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'postId' => 'post_id', 'slug' => 'slug', 'title' => 'title', 'price' => 'price', 'status' => 'status', 'visibility' => 'visibility', 'publishedAt' => 'published_at'],
        'Tag' => ['label' => 'label'],
    ],
    joinTables: [
        'phe_post_tags' => new TableSchema(
            'phe_post_tags',
            [
                'post_id' => new Column('post_id', 'BIGINT UNSIGNED', false, false, null),
                'tag_id' => new Column('tag_id', 'BIGINT UNSIGNED', false, false, null),
            ],
            [
                'phe_post_tags_pair_uniq' => new Index('phe_post_tags_pair_uniq', ['post_id', 'tag_id'], true),
                'phe_post_tags_tag_id_idx' => new Index('phe_post_tags_tag_id_idx', ['tag_id'], false),
            ],
            '',
        ),
    ],
    taxonomies: [

    ],
    taxonomyPlacements: [

    ],
    accounts: [

    ],
);
