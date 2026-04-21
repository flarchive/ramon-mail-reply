app.initializers.add('ramon-mail-reply', () => {
  const trans = (key, fallback) => {
    const out = app.translator?.trans(key);
    return out && out !== key ? out : fallback;
  };

  const t = (k, fb) => trans(`ramon-mail-reply.admin.settings.${k}`, fb);

  app.registry
    .for('ramon-mail-reply')
    .registerSetting(
      {
        setting: 'ramon-mail-reply.domain',
        type: 'text',
        label: t('domain_label', 'Reply-to domain'),
        help: t('domain_help', 'Domain that receives inbound replies (e.g. reply.example.com). Leave blank to disable.'),
        placeholder: 'reply.example.com',
      },
      100,
    )
    .registerSetting(
      {
        setting: 'ramon-mail-reply.webhook_secret',
        type: 'text',
        label: t('webhook_secret_label', 'Webhook shared secret'),
        help: t('webhook_secret_help', 'Required header X-Mail-Reply-Token for POST /api/mail-reply/inbound.'),
        placeholder: '••••••••',
      },
      90,
    )
    .registerSetting(
      {
        setting: 'ramon-mail-reply.secret',
        type: 'text',
        label: t('secret_label', 'HMAC key (optional)'),
        help: t('secret_help', 'Key used to sign reply tokens. Leave blank to use APP_KEY.'),
      },
      80,
    )
    .registerSetting(
      {
        setting: 'ramon-mail-reply.token_ttl_days',
        type: 'number',
        label: t('token_ttl_days_label', 'Token TTL (days)'),
        help: t('token_ttl_days_help', 'How long a unique Reply-To address stays valid. 0 = never expires.'),
        min: 0,
        step: 1,
      },
      70,
    )
    .registerSetting(
      {
        setting: 'ramon-mail-reply.strip_signatures',
        type: 'boolean',
        label: t('strip_signatures_label', 'Strip signatures and quoted replies'),
        help: t('strip_signatures_help', 'Run the full reply parser to drop signatures and quoted history from inbound emails.'),
      },
      60,
    )
    .registerSetting(
      {
        setting: 'ramon-mail-reply.show_badge',
        type: 'boolean',
        label: t('show_badge_label', 'Show "via email" badge on replies'),
        help: t('show_badge_help', 'Display a small badge next to posts that were created from an inbound email reply.'),
      },
      50,
    );
});
