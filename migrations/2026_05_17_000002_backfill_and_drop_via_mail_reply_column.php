<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;

/**
 * Migra o boolean `posts.via_mail_reply` (legado, em tabela do core) para
 * rows na companion table `mail_reply_posts` e dropa a coluna. Idempotente
 * em ambas as direções — install nova nem entra no backfill porque a
 * coluna não existe.
 *
 * Down recria a coluna E faz backfill reverso. O operador que reverter
 * mantém o flag visível mesmo depois da revert.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('posts') || ! $schema->hasColumn('posts', 'via_mail_reply')) {
            return;
        }

        /** @var ConnectionInterface $db */
        $db = $schema->getConnection();

        $db->statement(
            'INSERT IGNORE INTO mail_reply_posts (post_id)
             SELECT id FROM posts WHERE via_mail_reply = 1'
        );

        $schema->table('posts', function ($table) {
            $table->dropColumn('via_mail_reply');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('posts')) {
            return;
        }

        if (! $schema->hasColumn('posts', 'via_mail_reply')) {
            $schema->table('posts', function ($table) {
                $table->boolean('via_mail_reply')->default(false);
            });
        }

        if ($schema->hasTable('mail_reply_posts')) {
            /** @var ConnectionInterface $db */
            $db = $schema->getConnection();

            $db->statement(
                'UPDATE posts
                 SET via_mail_reply = 1
                 WHERE id IN (SELECT post_id FROM mail_reply_posts)'
            );
        }
    },
];
