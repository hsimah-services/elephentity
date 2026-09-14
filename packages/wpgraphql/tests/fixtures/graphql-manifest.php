<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * The compiled GraphQL surface.
 *
 * Loaded at boot and registered as-is: every decision was made when this was
 * compiled, so nothing here is worked out per request.
 */
return new Manifest(
    objects: [
        'Author' => new ObjectTypeEntry(
            'Author',
            'Author',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', true, false), 'getId', 'The globally unique identifier, opaque and safe to use as a cache key.', FieldEncoding::GlobalId, null),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', true, false), 'getId', 'The row as storage knows it, unique within its table rather than the schema.', FieldEncoding::Id, null),
                'name' => new FieldEntry('name', new GraphQLType('String', true, false), 'getName', null, FieldEncoding::Value, null),
            ],
            [
                'comments' => new ConnectionEntry('comments', 'Author', 'Comment', 'comments', 'author', 'The Comment pointing here through "author".'),
            ],
            'Someone who writes comments.',
            ['Node'],
        ),
        'Comment' => new ObjectTypeEntry(
            'Comment',
            'Comment',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', true, false), 'getId', 'The globally unique identifier, opaque and safe to use as a cache key.', FieldEncoding::GlobalId, null),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', true, false), 'getId', 'The row as storage knows it, unique within its table rather than the schema.', FieldEncoding::Id, null),
                'body' => new FieldEntry('body', new GraphQLType('String', true, false), 'getBody', null, FieldEncoding::Value, null),
                'author' => new FieldEntry('author', new GraphQLType('Author', false, false), 'getAuthor', 'Who wrote it.', FieldEncoding::Value, null),
                'post' => new FieldEntry('post', new GraphQLType('Post', false, false), 'getPost', 'The Post pointing here through "comments".', FieldEncoding::Value, null),
            ],
            [],
            null,
            ['Node'],
        ),
        'Post' => new ObjectTypeEntry(
            'Post',
            'Post',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', true, false), 'getId', 'The globally unique identifier, opaque and safe to use as a cache key.', FieldEncoding::GlobalId, null),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', true, false), 'getId', 'The row as storage knows it, unique within its table rather than the schema.', FieldEncoding::Id, null),
                'createdAt' => new FieldEntry('createdAt', new GraphQLType('String', true, false), 'getCreatedAt', 'When the row was first written. Filled by the framework.', FieldEncoding::Datetime, null),
                'updatedAt' => new FieldEntry('updatedAt', new GraphQLType('String', true, false), 'getUpdatedAt', 'When the row was last written. Filled by the framework.', FieldEncoding::Datetime, null),
                'postId' => new FieldEntry('postId', new GraphQLType('Int', false, false), 'getPostId', null, FieldEncoding::Value, null),
                'slug' => new FieldEntry('slug', new GraphQLType('String', true, false), 'getSlug', 'Write-once, and chosen by whoever creates the post.', FieldEncoding::Value, null),
                'title' => new FieldEntry('title', new GraphQLType('String', true, false), 'getTitle', null, FieldEncoding::Value, null),
                'price' => new FieldEntry('price', new GraphQLType('Int', false, false), 'getPrice', null, FieldEncoding::Processor, 'Money'),
                'status' => new FieldEntry('status', new GraphQLType('PostStatus', true, false), 'getStatus', null, FieldEncoding::BackedEnum, null),
                'visibility' => new FieldEntry('visibility', new GraphQLType('PostVisibility', true, false), 'getVisibility', null, FieldEncoding::BackedEnum, null),
                'publishedAt' => new FieldEntry('publishedAt', new GraphQLType('String', false, false), 'getPublishedAt', 'When it went live. A plain datetime, set by whoever publishes it.', FieldEncoding::Datetime, null),
            ],
            [
                'comments' => new ConnectionEntry('comments', 'Post', 'Comment', 'comments', 'comments', null),
                'tags' => new ConnectionEntry('tags', 'Post', 'Tag', 'tags', 'tags', null),
            ],
            'A published article.',
            ['Node'],
        ),
        'Tag' => new ObjectTypeEntry(
            'Tag',
            'Tag',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', true, false), 'getId', 'The globally unique identifier, opaque and safe to use as a cache key.', FieldEncoding::GlobalId, null),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', true, false), 'getId', 'The row as storage knows it, unique within its table rather than the schema.', FieldEncoding::Id, null),
                'label' => new FieldEntry('label', new GraphQLType('String', true, false), 'getLabel', null, FieldEncoding::Value, null),
            ],
            [
                'posts' => new ConnectionEntry('posts', 'Tag', 'Post', 'posts', 'tags', 'The Post pointing here through "tags".'),
            ],
            null,
            ['Node'],
        ),
    ],
    enums: [
        'PostStatus' => new EnumTypeEntry('PostStatus', ['DRAFT' => 'draft', 'SCHEDULED' => 'scheduled', 'PUBLISHED' => 'published']),
        'PostVisibility' => new EnumTypeEntry('PostVisibility', ['PUBLIC' => 'public', 'PRIVATE' => 'private']),
    ],
    mutations: [
        'createAuthor' => new MutationEntry(
            'createAuthor',
            'create',
            'Author',
            [
                'name' => new GraphQLType('String', true, false),
            ],
            null,
            'Create a Author.',
        ),
        'createComment' => new MutationEntry(
            'createComment',
            'create',
            'Comment',
            [
                'body' => new GraphQLType('String', true, false),
                'author' => new GraphQLType('ID', false, false),
            ],
            null,
            'Create a Comment.',
        ),
        'createPost' => new MutationEntry(
            'createPost',
            'create',
            'Post',
            [
                'postId' => new GraphQLType('Int', false, false),
                'slug' => new GraphQLType('String', false, false),
                'title' => new GraphQLType('String', true, false),
                'price' => new GraphQLType('Int', false, false),
                'status' => new GraphQLType('PostStatus', true, false),
                'visibility' => new GraphQLType('PostVisibility', false, false),
                'publishedAt' => new GraphQLType('String', false, false),
                'comments' => new GraphQLType('ID', false, true),
                'tags' => new GraphQLType('ID', false, true),
            ],
            null,
            'Create a Post.',
        ),
        'createTag' => new MutationEntry(
            'createTag',
            'create',
            'Tag',
            [
                'label' => new GraphQLType('String', true, false),
            ],
            null,
            'Create a Tag.',
        ),
        'publishPost' => new MutationEntry(
            'publishPost',
            'action',
            'Post',
            [
                'id' => new GraphQLType('ID', true, false),
                'at' => new GraphQLType('String', false, false),
            ],
            'publish',
            'Run publish on a Post.',
        ),
        'updateAuthor' => new MutationEntry(
            'updateAuthor',
            'update',
            'Author',
            [
                'id' => new GraphQLType('ID', true, false),
                'name' => new GraphQLType('String', false, false),
            ],
            null,
            'Update a Author.',
        ),
        'updateComment' => new MutationEntry(
            'updateComment',
            'update',
            'Comment',
            [
                'id' => new GraphQLType('ID', true, false),
                'body' => new GraphQLType('String', false, false),
                'author' => new GraphQLType('ID', false, false),
            ],
            null,
            'Update a Comment.',
        ),
        'updatePost' => new MutationEntry(
            'updatePost',
            'update',
            'Post',
            [
                'id' => new GraphQLType('ID', true, false),
                'postId' => new GraphQLType('Int', false, false),
                'title' => new GraphQLType('String', false, false),
                'price' => new GraphQLType('Int', false, false),
                'status' => new GraphQLType('PostStatus', false, false),
                'visibility' => new GraphQLType('PostVisibility', false, false),
                'publishedAt' => new GraphQLType('String', false, false),
                'comments' => new GraphQLType('ID', false, true),
                'tags' => new GraphQLType('ID', false, true),
            ],
            null,
            'Update a Post.',
        ),
        'updateTag' => new MutationEntry(
            'updateTag',
            'update',
            'Tag',
            [
                'id' => new GraphQLType('ID', true, false),
                'label' => new GraphQLType('String', false, false),
            ],
            null,
            'Update a Tag.',
        ),
    ],
    roots: [
        'Author' => new RootFieldEntry('Author', 'Authors', 'Author'),
        'Comment' => new RootFieldEntry('Comment', 'Comments', 'Comment'),
        'Post' => new RootFieldEntry('Post', 'Posts', 'Post'),
        'Tag' => new RootFieldEntry('Tag', 'Tags', 'Tag'),
    ],
    queries: [
        'publishedPosts' => new QueryFieldEntry(
            'publishedPosts',
            'Post',
            true,
            'Post',
            'published',
            ['limit' => new GraphQLType('Int', false, false)],
            null,
        ),
    ],
);
