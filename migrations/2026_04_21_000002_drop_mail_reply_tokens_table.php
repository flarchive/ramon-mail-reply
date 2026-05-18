<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

/**
 * A refatoração para token stateless (HMAC determinístico) aposentou a
 * tabela `mail_reply_tokens`. Idempotente: numa install nova a tabela
 * sequer existe; num upgrade, dropamos o estado morto.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_tokens')) {
            $schema->drop('mail_reply_tokens');
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_tokens')) {
            return;
        }

        $schema->create('mail_reply_tokens', function ($table) {
            $table->string('token', 64)->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('post_id')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();

            $table->index(['user_id', 'discussion_id']);
            $table->index('expires_at');
        });
    },
];
