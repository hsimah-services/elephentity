<?php
declare(strict_types=1);
namespace Eleph\GraphQL\Manifest;

/**
 * The compiled GraphQL surface.
 *
 * Loaded at boot and registered as-is: every decision was made when this was
 * compiled, so nothing here is worked out per request.
 */
return new Manifest(
    objects: [
        'Page' => new ObjectTypeEntry(
            'Page',
            'Page',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', true, false), 'getId', 'The globally unique identifier, opaque and safe to use as a cache key.', FieldEncoding::GlobalId, null),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', true, false), 'getId', 'The row as storage knows it, unique within its table rather than the schema.', FieldEncoding::Id, null),
                'title' => new FieldEntry('title', new GraphQLType('String', true, false), 'getTitle', null, FieldEncoding::Value, null),
                'slug' => new FieldEntry('slug', new GraphQLType('String', true, false), 'getSlug', null, FieldEncoding::Value, null),
                'content' => new FieldEntry('content', new GraphQLType('String', true, false), 'getContent', null, FieldEncoding::Value, null),
                'excerpt' => new FieldEntry('excerpt', new GraphQLType('String', true, false), 'getExcerpt', null, FieldEncoding::Value, null),
                'status' => new FieldEntry('status', new GraphQLType('Status', true, false), 'getStatus', null, FieldEncoding::BackedEnum, null),
                'publishedAt' => new FieldEntry('publishedAt', new GraphQLType('String', false, false), 'getPublishedAt', null, FieldEncoding::Datetime, null),
                'enquiryUrl' => new FieldEntry('enquiryUrl', new GraphQLType('String', false, false), 'getEnquiryUrl', null, FieldEncoding::Value, null),
                'active' => new FieldEntry('active', new GraphQLType('Boolean', true, false), 'getActive', null, FieldEncoding::Value, null),
                'zero' => new FieldEntry('zero', new GraphQLType('Int', true, false), 'getZero', null, FieldEncoding::Value, null),
                'disabled' => new FieldEntry('disabled', new GraphQLType('Boolean', true, false), 'getDisabled', null, FieldEncoding::Value, null),
                'metadata' => new FieldEntry('metadata', new GraphQLType('String', true, false), 'getMetadata', null, FieldEncoding::Json, null),
                'createdAt' => new FieldEntry('createdAt', new GraphQLType('String', true, false), 'getCreatedAt', null, FieldEncoding::Datetime, null),
                'rank' => new FieldEntry('rank', new GraphQLType('Int', true, false), 'getRank', null, FieldEncoding::Value, null),
                'amount' => new FieldEntry('amount', new GraphQLType('String', true, false), 'getAmount', null, FieldEncoding::Processor, 'Amount'),
            ],
            [],
            null,
            ['Node'],
        ),
    ],
    enums: [
        'Role' => new EnumTypeEntry('Role', ['EDITOR' => 'editor', 'VIEWER' => 'viewer']),
        'Status' => new EnumTypeEntry('Status', ['DRAFT' => 'draft', 'PUBLISHED' => 'published']),
    ],
    mutations: [
        'createPage' => new MutationEntry(
            'createPage',
            'create',
            'Page',
            [
                'title' => new GraphQLType('String', true, false),
                'slug' => new GraphQLType('String', true, false),
                'content' => new GraphQLType('String', false, false),
                'excerpt' => new GraphQLType('String', false, false),
                'status' => new GraphQLType('Status', false, false),
                'publishedAt' => new GraphQLType('String', false, false),
                'enquiryUrl' => new GraphQLType('String', false, false),
                'active' => new GraphQLType('Boolean', false, false),
                'zero' => new GraphQLType('Int', false, false),
                'disabled' => new GraphQLType('Boolean', false, false),
                'metadata' => new GraphQLType('String', false, false),
                'rank' => new GraphQLType('Int', false, false),
                'amount' => new GraphQLType('String', false, false),
            ],
            null,
            'Create a Page.',
        ),
        'updatePage' => new MutationEntry(
            'updatePage',
            'update',
            'Page',
            [
                'id' => new GraphQLType('ID', true, false),
                'title' => new GraphQLType('String', false, false),
                'slug' => new GraphQLType('String', false, false),
                'content' => new GraphQLType('String', false, false),
                'excerpt' => new GraphQLType('String', false, false),
                'status' => new GraphQLType('Status', false, false),
                'publishedAt' => new GraphQLType('String', false, false),
                'enquiryUrl' => new GraphQLType('String', false, false),
                'active' => new GraphQLType('Boolean', false, false),
                'zero' => new GraphQLType('Int', false, false),
                'disabled' => new GraphQLType('Boolean', false, false),
                'metadata' => new GraphQLType('String', false, false),
                'rank' => new GraphQLType('Int', false, false),
                'amount' => new GraphQLType('String', false, false),
            ],
            null,
            'Update a Page.',
        ),
    ],
    roots: [
        'Page' => new RootFieldEntry('Page', 'Pages', 'Page'),
    ],
    queries: [
        'pageBySlug' => new QueryFieldEntry(
            'pageBySlug',
            'Page',
            false,
            'Page',
            'bySlug',
            ['slug' => new GraphQLType('String', true, false)],
            null,
        ),
        'searchPages' => new QueryFieldEntry(
            'searchPages',
            'Page',
            true,
            'Page',
            'search',
            ['limit' => new GraphQLType('Int', false, false)],
            null,
        ),
    ],
);
