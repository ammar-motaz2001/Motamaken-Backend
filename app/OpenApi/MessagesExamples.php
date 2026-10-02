<?php

namespace App\OpenApi;

/**
 * Swagger examples for the Messages page endpoints. See ApiExamples for how they are applied.
 */
class MessagesExamples
{
    /**
     * @return array<string, array{request?: array, requestJson?: array, requestMultipart?: array, responses?: array<int, array{0: string, 1: array}|null>}>
     */
    public static function operations(): array
    {
        $hosaam = ['id' => 8, 'fullName' => 'Hosaam Amar', 'username' => 'hosaam', 'avatarUrl' => null, 'isOnline' => true];
        $mostafa = ['id' => 9, 'fullName' => 'Mostafa Salem', 'username' => 'mostafa', 'avatarUrl' => null, 'isOnline' => false];

        $conversation = fn (int $id, array $participant, string $preview, int $unread, bool $mine = false, ?array $project = null) => [
            'id' => $id,
            'participant' => $participant,
            'lastMessage' => ['id' => $id * 10, 'type' => 'text', 'preview' => $preview, 'isMine' => $mine, 'createdAt' => '2026-10-02T10:43:37.000000Z'],
            'unreadCount' => $unread,
            'project' => $project,
            'updatedAt' => '2026-10-02T10:43:37.000000Z',
        ];

        $unread = $conversation(1, $hosaam, 'Sure, send me the details and your budget and I will start today.', 2);
        $read = $conversation(2, $mostafa, 'Okay 🙌🏻', 0, true, ['id' => 3, 'title' => 'Online store for handmade furniture']);

        $text = self::message(31, 8, false, 'text', "I'm doing well, thank you! How can I help you today?");
        $mine = self::message(32, 7, true, 'text', 'How are you', status: 'read');
        $voice = self::message(33, 8, false, 'voice', null, duration: 130, attachment: [
            'name' => 'voice.m4a', 'mime' => 'audio/x-m4a', 'size' => 14676, 'url' => self::URL.'33/attachment?expires=1790962347&signature=95d772fbcab5624d7eae3f781fd37b626fffe6d4f937cd6798945069e458bad3',
        ]);
        $file = self::message(34, 7, true, 'attachment', 'the brief', attachment: [
            'name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 482113, 'url' => self::URL.'34/attachment?expires=1790962351&signature=06d5fad18559b12b144f742a0cd213a3b6effbafaa5e3713099953e7a2ac025d',
        ], status: 'sent');

        $notFound = [404 => ['Not found, or not your conversation', ['Not found' => ['message' => 'No query results for model [App\\Models\\Conversation] 999']]]];

        return [
            'GET /conversations' => [
                'responses' => [
                    200 => ['The left column, newest first by default', [
                        'Conversations' => HomepageExamples::paginated('/conversations', [$unread, $read], 2, 20),
                        'Empty inbox' => HomepageExamples::paginated('/conversations', [], 0, 20),
                    ]],
                    422 => ['Invalid sort', [
                        'Unknown sort' => ApiExamples::validation('sort', ['The selected sort is invalid.']),
                    ]],
                ] + ApiExamples::unauthenticated(),
            ],

            'POST /conversations' => [
                'request' => [
                    'From an ad, with a first message' => ['userId' => 8, 'projectId' => 3, 'body' => 'Hello! I saw your profile and I have a project for you.'],
                    'Just open it' => ['userId' => 8],
                ],
                'responses' => [
                    201 => ['Conversation created', ['Created' => ['data' => $unread]]],
                    200 => ['The two of you already had one: returned as is', ['Existing' => ['data' => $read]]],
                    422 => ['Invalid input', [
                        'Yourself' => ApiExamples::validation('userId', ['You cannot start a conversation with yourself.']),
                        'Unknown user' => ApiExamples::validation('userId', ['The selected user id is invalid.']),
                    ]],
                    429 => ['Too many messages', ['Rate limited' => ['message' => 'Too many attempts. Please try again in a minute.']]],
                ] + ApiExamples::unauthenticated(),
            ],

            'GET /conversations/{conversation}' => [
                'responses' => [
                    200 => ['The conversation', ['Conversation' => ['data' => $unread]]],
                ] + $notFound + ApiExamples::unauthenticated(),
            ],

            'POST /conversations/{conversation}/read' => [
                'responses' => [
                    200 => ['How many received messages were marked as read', [
                        'Marked' => ['marked' => 2],
                        'Already read' => ['marked' => 0],
                    ]],
                ] + $notFound + ApiExamples::unauthenticated(),
            ],

            'GET /conversations/{conversation}/messages' => [
                'responses' => [
                    200 => ['The chat window, oldest message first', [
                        'Text, voice and file messages' => ['data' => [$text, $voice, $mine, $file], 'meta' => ['hasMore' => true]],
                        'Nothing newer (polling)' => ['data' => [], 'meta' => ['hasMore' => false]],
                    ]],
                    422 => ['Invalid parameter', [
                        'Bad limit' => ApiExamples::validation('limit', ['The limit field must be at least 1.']),
                    ]],
                ] + $notFound + ApiExamples::unauthenticated(),
            ],

            'POST /conversations/{conversation}/messages' => [
                'requestMultipart' => [
                    'Voice message (send file + duration)' => ['type' => 'voice', 'duration' => 130],
                    'Attachment with a caption (send file)' => ['type' => 'attachment', 'body' => 'the brief'],
                ],
                'requestJsonSchema' => 'SendTextMessageRequest',
                'requestJson' => [
                    'Text' => ['body' => 'Okay 🙌🏻 sounds good'],
                ],
                'responses' => [
                    201 => ['The sent message', [
                        'Text' => ['data' => self::message(35, 7, true, 'text', 'Okay 🙌🏻 sounds good', status: 'sent')],
                        'Voice message' => ['data' => self::message(36, 7, true, 'voice', null, duration: 130, status: 'sent', attachment: [
                            'name' => 'voice.m4a', 'mime' => 'audio/x-m4a', 'size' => 14676, 'url' => self::URL.'36/attachment?expires=1790962347&signature=95d772fbcab5624d7eae3f781fd37b626fffe6d4f937cd6798945069e458bad3',
                        ])],
                        'Attachment' => ['data' => $file],
                    ]],
                    422 => ['Invalid message', [
                        'Empty text' => ApiExamples::validation('body', ['Write a message before sending.']),
                        'Voice without length' => ApiExamples::validation('duration', ['The length of the voice message in seconds is required.']),
                        'File type not allowed' => ApiExamples::validation('file', ['The file field must be a file of type: jpg, jpeg, png, gif, webp, pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv, zip, rar.']),
                    ]],
                    429 => ['Too many messages', ['Rate limited' => ['message' => 'Too many attempts. Please try again in a minute.']]],
                ] + $notFound + ApiExamples::unauthenticated(),
            ],

            'GET /messages/{message}/attachment' => [
                'responses' => [
                    200 => ['The file: shown inline for images and voice messages, downloaded for documents', []],
                    403 => ['The link is missing its signature, was altered, or expired', ['Invalid signature' => ['message' => 'Invalid signature.']]],
                ],
            ],
        ];
    }

    private const URL = 'http://localhost:8000/api/messages/';

    /**
     * @param  array<string, mixed>|null  $attachment
     * @return array<string, mixed>
     */
    private static function message(int $id, int $senderId, bool $mine, string $type, ?string $body, ?int $duration = null, ?array $attachment = null, ?string $status = null): array
    {
        return array_filter([
            'id' => $id,
            'conversationId' => 1,
            'senderId' => $senderId,
            'isMine' => $mine,
            'type' => $type,
            'body' => $body,
            'duration' => $duration,
            'attachment' => $attachment,
            'status' => $status,
            'readAt' => $status === 'read' ? '2026-10-02T10:40:00.000000Z' : null,
            'createdAt' => '2026-10-02T10:35:37.000000Z',
        ], fn ($value, $key) => $value !== null || in_array($key, ['body', 'duration', 'status', 'readAt'], true), ARRAY_FILTER_USE_BOTH);
    }
}
