<?php

/**
 * SocialAPI MCP tool routing.
 * category null + mutate heuristic => confirm required (unknown writes).
 * force_read / force_mutate override name heuristics.
 * read_tools: preferred MCP tool name aliases for get_business_context live sections.
 */
return [
    'categories' => [
        'posts' => 'ai_auto_publish_posts',
        'comments' => 'ai_auto_reply_comments',
        'dms' => 'ai_auto_send_dms',
        'reviews' => 'ai_auto_reply_reviews',
        'moderate' => 'ai_auto_moderate',
    ],
    'force_read' => [],
    'force_mutate' => [],
    'tool_category' => [
        // Explicit post writes → publish gate
        'create_post' => 'posts',
        'update_post' => 'posts',
        'delete_post' => 'posts',
        'publish_post' => 'posts',
        'unpublish_post' => 'posts',
        'retry_post' => 'posts',
        'schedule_post' => 'posts',
        'create_scheduled_post' => 'posts',
    ],
    'read_tools' => [
        'posts' => ['social_api_list_posts', 'list_posts', 'posts_list', 'get_posts'],
        'comments' => ['social_api_list_post_comments', 'list_comments', 'comments_list', 'list_commented_posts'],
        'dms' => ['social_api_list_conversations', 'list_conversations', 'list_dms', 'list_messages', 'get_conversations'],
    ],
    'campaign_tools' => [
        // Defaults used when tools/list is unavailable (tests / outages). Live schema remaps via candidates.
        'list_posts' => 'list_posts',
        'create_post' => 'create_post',
        'upload_media' => 'upload_media',
        'delete_post' => 'delete_post',
    ],
    // Tried in order against the live tools/list when the configured name is missing.
    'campaign_tool_candidates' => [
        'list_posts' => ['social_api_list_posts', 'list_posts', 'posts_list', 'get_posts'],
        'create_post' => ['social_api_create_post', 'create_post', 'schedule_post', 'create_scheduled_post', 'posts_create'],
        'upload_media' => ['upload_media', 'upload_media_from_url', 'media_upload', 'create_media', 'social_api_get_media_upload_url'],
        'delete_post' => ['social_api_delete_post', 'delete_post', 'posts_delete'],
    ],
    // Platforms where SocialAPI can publish stories.
    'story_platforms' => ['instagram', 'facebook'],
];
