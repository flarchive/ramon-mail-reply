import ExtensionPage from 'flarum/admin/components/ExtensionPage';

app.initializers.add('ramon-mail-reply', () => {
  const trans = (key, fallback) => {
    const out = app.translator?.trans(key);
    return out && out !== key ? out : fallback;
  };
  const t = (k, fb) => trans(`ramon-mail-reply.admin.settings.${k}`, fb);

  // ─── Script PHP gerado dinamicamente com URL/secret atuais ───
  const buildPhpScript = (apiUrl, secret) => `#!/usr/bin/env php
<?php
/**
 * Mail Reply — encaminhador inbound para Flarum.
 * Cole em /usr/local/bin/forward-to-flarum.php, chmod 755, e crie
 * um Forwarder Pipe no DA/cPanel: |/usr/bin/php /usr/local/bin/forward-to-flarum.php
 *
 * Logs:
 *   - syslog tag "forward-to-flarum"  (journalctl -t forward-to-flarum)
 *   - /tmp/forward-to-flarum.last
 *
 * Exit codes:
 *   0 → sucesso    75 → TEMPFAIL (Exim retenta)    65 → PERMFAIL (bounce)
 */

$FLARUM_URL     = '${apiUrl}';
$WEBHOOK_SECRET = '${secret || 'COLE_O_SEGREDO_AQUI'}';

openlog('forward-to-flarum', LOG_PID | LOG_NDELAY, LOG_MAIL);
function logmsg(int $priority, string $msg): void { syslog($priority, $msg); }

logmsg(LOG_INFO, sprintf('INVOKED user=%s pid=%d', get_current_user(), getmypid()));

$body = stream_get_contents(STDIN);
$bodyLen = strlen($body);
logmsg(LOG_INFO, "STDIN bytes={$bodyLen}");

@file_put_contents('/tmp/forward-to-flarum.raw.eml', $body);

if ($bodyLen === 0) { logmsg(LOG_ERR, 'PERMFAIL stdin_empty'); closelog(); exit(65); }
if (! function_exists('curl_init')) { logmsg(LOG_ERR, 'php-curl required'); closelog(); exit(75); }

$ch = curl_init($FLARUM_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ["X-Mail-Reply-Token: {$WEBHOOK_SECRET}", 'Content-Type: message/rfc822'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

@file_put_contents('/tmp/forward-to-flarum.last', sprintf("%s\\n--- HTTP %d ---\\n%s\\n", date('c'), $status, (string) $response));
logmsg(LOG_INFO, "RESPONSE http={$status} body=" . substr((string) $response, 0, 300));

if ($err !== '') { logmsg(LOG_ERR, "TEMPFAIL curl={$err}"); closelog(); exit(75); }
if ($status === 0 || $status >= 500) { logmsg(LOG_ERR, "TEMPFAIL http={$status}"); closelog(); exit(75); }
if ($status < 200 || $status >= 300) { logmsg(LOG_ERR, "PERMFAIL http={$status}"); closelog(); exit(65); }

$decoded = @json_decode((string) $response, true);
if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
    logmsg(LOG_ERR, "PERMFAIL processor_rejected reason='" . ($decoded['reason'] ?? '?') . "'");
    closelog();
    exit(65);
}

logmsg(LOG_INFO, "SUCCESS post_id=" . ($decoded['post_id'] ?? '?') . " discussion_id=" . ($decoded['discussion_id'] ?? '?'));
closelog();
exit(0);
`;

  // ─── ExtensionPage com pills horizontais ─────────────────────
  class MailReplyPage extends ExtensionPage {
    content() {
      const mode = this.setting('ramon-mail-reply.delivery_mode')() || 'imap';

      // Tabs disponíveis dependem do modo. Geral sempre. IMAP/Webhook
      // só quando ativos.
      const allTabs = [
        ['general', 'fas fa-cog', t('tab_general', 'Geral')],
      ];
      if (mode === 'webhook') allTabs.push(['webhook', 'fas fa-plug', t('tab_webhook', 'PIPE (Webhook)')]);
      if (mode === 'imap') allTabs.push(['imap', 'fas fa-inbox', t('tab_imap', 'IMAP')]);

      // Tab requisitada via querystring; default geral. Se a tab pedida
      // não existe no modo atual, volta pra geral.
      let activeTab = m.route.param('tab') || 'general';
      if (!allTabs.find(([id]) => id === activeTab)) {
        activeTab = 'general';
      }

      const base = app.route('extension', { id: 'ramon-mail-reply' });
      const hrefFor = (tab) => (tab === 'general' ? base : base + '?tab=' + tab);

      return m('.ExtensionPage-settings.MailReplyAdmin', [
        m('nav.MailReplyAdminNav', allTabs.map(([id, icon, label]) =>
          m('a.MailReplyAdminNav-link', {
            key: id,
            className: activeTab === id ? 'active' : '',
            href: hrefFor(id),
            onclick: (e) => {
              e.preventDefault();
              m.route.set(hrefFor(id));
            },
          }, [m('i', { className: icon }), m('span', label)]),
        )),

        m('.container.MailReplyAdmin-body', this.panelFor(activeTab, mode)),
      ]);
    }

    panelFor(tab, mode) {
      if (tab === 'webhook') return this.webhookPanel(mode);
      if (tab === 'imap') return this.imapPanel(mode);
      return this.generalPanel(mode);
    }

    // ─── Painel Geral ────────────────────────────────────────
    generalPanel(mode) {
      return m('.MailReplyAdmin-section', [
        m('.MailReplyAdmin-section-header', [
          m('h2', t('general_section_title', 'Configuração geral')),
          m('p.helpText', t('general_section_help', 'Modo de entrega, caixa de resposta e segurança do alias.')),
        ]),

        // Modo
        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('delivery_mode_label', 'Modo de entrega')),
            m('p.helpText', t('delivery_mode_help', 'Escolha UMA forma de receber respostas.')),
          ]),
          this.buildSettingComponent({
            setting: 'ramon-mail-reply.delivery_mode',
            type: 'select',
            label: '',
            options: {
              imap: t('mode_imap_option', 'IMAP polling (roda dentro do queue:work)'),
              webhook: t('mode_webhook_option', 'Webhook + script PHP no servidor de e-mail'),
              disabled: t('mode_disabled_option', 'Desativado'),
            },
            default: 'imap',
          }),
          m('.MailReplyAdmin-modeStatus', { 'data-mode': mode }, this.modeDescription(mode)),
        ]),

        // Caixa de resposta
        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('reply_address_label', 'Caixa de resposta')),
            m('p.helpText', t('reply_address_help', 'Caixa dedicada que recebe respostas. Deve aceitar qualquer "+TAG" sob o mesmo local part.')),
          ]),
          this.buildSettingComponent({
            setting: 'ramon-mail-reply.reply_address',
            type: 'text',
            label: '',
            placeholder: 'inbox@reply.example.com',
          }),
          this.mailboxHint(mode),
        ]),

        // Segurança
        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('secret_label', 'Chave HMAC (avançado)')),
            m('p.helpText', t('secret_help', 'Assina aliases. Em branco gera automaticamente. NÃO troque depois que e-mails saíram.')),
          ]),
          this.buildSettingComponent({
            setting: 'ramon-mail-reply.secret',
            type: 'text',
            label: '',
          }),
        ]),

        // Aparência
        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('appearance_section', 'Aparência')),
          ]),
          m('.MailReplyAdmin-toggleList', [
            m('.MailReplyAdmin-toggleRow', this.buildSettingComponent({
              setting: 'ramon-mail-reply.show_badge',
              type: 'boolean',
              label: t('show_badge_label', 'Mostrar selo "via e-mail"'),
              help: t('show_badge_help', 'Selo discreto em posts criados via e-mail.'),
            })),
          ]),
        ]),

        this.stickyActions(),
      ]);
    }

    // ─── Painel Webhook ──────────────────────────────────────
    webhookPanel() {
      const secret = this.setting('ramon-mail-reply.webhook_secret')() || '';
      const apiUrl = (app.forum.attribute('apiUrl') || '') + '/mail-reply/inbound';
      const script = buildPhpScript(apiUrl, secret);

      return m('.MailReplyAdmin-section', [
        m('.MailReplyAdmin-section-header', [
          m('h2', t('webhook_section_title', 'Webhook (PIPE)')),
          m('p.helpText', t('webhook_section_help', 'Configure um provedor externo (Mailgun/Postmark/Cloudflare) OU rode o script PHP no servidor de e-mail.')),
        ]),

        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('webhook_secret_label', 'Segredo do webhook')),
            m('p.helpText', t('webhook_secret_help', 'Header X-Mail-Reply-Token no POST. Gere com: openssl rand -hex 32')),
          ]),
          this.buildSettingComponent({
            setting: 'ramon-mail-reply.webhook_secret',
            type: 'text',
            label: '',
            placeholder: '••••••••',
          }),
        ]),

        this.providersBlock(apiUrl, secret),
        this.scriptBlock(script),
        this.stickyActions(),
      ]);
    }

    // ─── Painel IMAP ─────────────────────────────────────────
    imapPanel() {
      return m('.MailReplyAdmin-section', [
        m('.MailReplyAdmin-section-header', [
          m('h2', t('imap_section_title', 'IMAP')),
          m('p.helpText', t('imap_section_help', 'Servidor que será consultado a cada 60s pelo queue:work.')),
        ]),

        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('imap_server_card', 'Servidor')),
          ]),
          m('.MailReplyAdmin-fieldGrid', [
            this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_host',
              type: 'text',
              label: t('imap_host_label', 'Servidor IMAP'),
              help: t('imap_host_help', 'Requer webklex/php-imap.'),
              placeholder: 'imap.example.com',
            }),
            this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_port',
              type: 'number',
              label: t('imap_port_label', 'Porta IMAP'),
              help: t('imap_port_help', '993 SSL/TLS, 143 STARTTLS.'),
              min: 1,
              step: 1,
              placeholder: '993',
            }),
            this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_encryption',
              type: 'select',
              label: t('imap_encryption_label', 'Criptografia IMAP'),
              options: { ssl: 'SSL/TLS', tls: 'STARTTLS', none: 'None' },
              default: 'ssl',
            }),
            this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_mailbox',
              type: 'text',
              label: t('imap_mailbox_label', 'Pasta IMAP'),
              placeholder: 'INBOX',
            }),
          ]),
        ]),

        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('imap_auth_card', 'Autenticação')),
          ]),
          m('.MailReplyAdmin-fieldGrid', [
            this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_username',
              type: 'text',
              label: t('imap_username_label', 'Usuário IMAP'),
              placeholder: 'inbox@reply.example.com',
            }),
            this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_password',
              type: 'text',
              label: t('imap_password_label', 'Senha IMAP'),
              help: t('imap_password_help', 'App-password recomendado.'),
              placeholder: '••••••••',
            }),
          ]),
        ]),

        m('.MailReplyAdmin-card', [
          m('.MailReplyAdmin-card-header', [
            m('h3', t('imap_options_card', 'Opções')),
          ]),
          m('.MailReplyAdmin-toggleList', [
            m('.MailReplyAdmin-toggleRow', this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_delete_after',
              type: 'boolean',
              label: t('imap_delete_after_label', 'Apagar mensagens após coleta'),
              help: t('imap_delete_after_help', 'Quando desativado, mensagens são apenas marcadas como lidas — útil em testes.'),
            })),
            m('.MailReplyAdmin-toggleRow', this.buildSettingComponent({
              setting: 'ramon-mail-reply.imap_validate_cert',
              type: 'boolean',
              label: t('imap_validate_cert_label', 'Validar certificado TLS'),
              help: t('imap_validate_cert_help', 'Mantenha ativado em produção.'),
            })),
          ]),
        ]),

        m('.MailReplyAdmin-card', this.imapTestButton()),

        this.stickyActions(),
      ]);
    }

    // ─── Helpers visuais ─────────────────────────────────────
    stickyActions() {
      return m('.MailReplyAdmin-actions.MailReplyAdmin-actions--sticky', this.submitButton());
    }

    modeDescription(mode) {
      if (mode === 'imap') return t('mode_imap_active', '✓ IMAP poller ativo. O job mail-reply:fetch roda dentro do queue:work e consulta a caixa a cada minuto.');
      if (mode === 'webhook') return t('mode_webhook_active', '✓ Webhook ativo. POST para /api/mail-reply/inbound com X-Mail-Reply-Token. IMAP poller pausado.');
      return t('mode_disabled_active', '✗ Inbound desativado. Notificações ainda saem com Reply-To, mas respostas não voltam.');
    }

    mailboxHint(mode) {
      const replyAddress = this.setting('ramon-mail-reply.reply_address')() || '';
      const isValid = replyAddress && replyAddress.includes('@');
      const [localRaw, domain] = isValid ? replyAddress.split('@') : ['', ''];
      const local = (localRaw || '').split('+')[0];
      const base = isValid ? `${local}@${domain}` : '—';
      const wildcard = isValid ? `${local}+u{userId}d{discussionId}p{postId}-{sig}@${domain}` : '—';

      const baseLabel = mode === 'imap'
        ? t('mailbox_base_label_imap', 'Caixa de resposta (alvo do IMAP):')
        : (mode === 'webhook'
          ? t('mailbox_base_label_webhook', 'Caixa de resposta (capturada pelo PIPE/script):')
          : t('mailbox_base_label_disabled', 'Caixa de resposta:'));

      return m('.MailReplyAdmin-hint', [
        m('.MailReplyAdmin-hint-row', [m('strong', baseLabel), m('code', base)]),
        m('.MailReplyAdmin-hint-row', [m('strong', t('mailbox_alias_label', 'Padrão do Reply-To:')), m('code', wildcard)]),
        !isValid ? m('.MailReplyAdmin-hint-pending', t('mailbox_pending', 'Preencha a "Caixa de resposta" acima para ver o padrão.')) : null,
      ]);
    }

    providersBlock(apiUrl, secret) {
      const copyValue = (text, label) => {
        if (navigator?.clipboard?.writeText) {
          navigator.clipboard.writeText(text);
          app.alerts.show({ type: 'success' }, label + ' copiado.');
        }
      };

      return m('.MailReplyAdmin-card', [
        m('.MailReplyAdmin-card-header', [
          m('h3', t('providers_label', 'Provedores externos (Mailgun, Postmark, Cloudflare)')),
          m('p.helpText', t('providers_help', 'Configure o provedor pra POST direto pro endpoint — sem script.')),
        ]),

        m('.MailReplyAdmin-kv', [
          m('.MailReplyAdmin-kv-row', [
            m('strong', t('providers_url_label', 'URL do webhook:')),
            m('code', apiUrl),
            m('button.Button.Button--link', { type: 'button', onclick: () => copyValue(apiUrl, 'URL') }, m('i.fas.fa-copy')),
          ]),
          m('.MailReplyAdmin-kv-row', [
            m('strong', t('providers_header_label', 'Header obrigatório:')),
            m('code', `X-Mail-Reply-Token: ${secret || '<configure o segredo acima>'}`),
            secret ? m('button.Button.Button--link', { type: 'button', onclick: () => copyValue(`X-Mail-Reply-Token: ${secret}`, 'Header') }, m('i.fas.fa-copy')) : null,
          ]),
        ]),

        m('details.MailReplyAdmin-details', [
          m('summary', t('providers_mailgun_summary', 'Como configurar no Mailgun')),
          m('ol', [
            m('li', t('providers_mailgun_step1', 'Mailgun → Receiving → Routes → Create Route')),
            m('li', [t('providers_mailgun_step2', 'Match Recipient para'), ' ', m('code', '^reply\\+.*@seudominio.com$')]),
            m('li', [t('providers_mailgun_step3', 'Action: Forward →'), ' ', m('code', apiUrl)]),
            m('li', t('providers_mailgun_step4', 'Routes padrão não suporta custom headers — use store-and-notify ou o script PHP.')),
          ]),
        ]),

        m('details.MailReplyAdmin-details', [
          m('summary', t('providers_postmark_summary', 'Como configurar no Postmark')),
          m('ol', [
            m('li', t('providers_postmark_step1', 'Postmark → Inbound Stream → Create')),
            m('li', [t('providers_postmark_step2', 'Webhook URL:'), ' ', m('code', apiUrl)]),
            m('li', [t('providers_postmark_step3', 'Custom Header:'), ' ', m('code', `X-Mail-Reply-Token: ${secret || '<seu-segredo>'}`)]),
            m('li', t('providers_postmark_step4', 'Postmark envia JSON com ToFull/TextBody — endpoint aceita nativamente.')),
          ]),
        ]),

        m('details.MailReplyAdmin-details', [
          m('summary', t('providers_cloudflare_summary', 'Como usar Cloudflare Email Routing')),
          m('ol', [
            m('li', t('providers_cf_step1', 'Cloudflare → Email → Email Routing → Email Workers')),
            m('li', t('providers_cf_step2', 'Worker recebe ForwardableEmailMessage e faz fetch() pro endpoint abaixo.')),
            m('li', m('code', apiUrl)),
            m('li', [t('providers_cf_step3', 'Header:'), ' ', m('code', `X-Mail-Reply-Token: ${secret || '<seu-segredo>'}`)]),
            m('li', t('providers_cf_step4', 'Use message.raw como body. Content-Type: message/rfc822.')),
          ]),
        ]),
      ]);
    }

    scriptBlock(script) {
      const copyScript = () => {
        if (navigator?.clipboard?.writeText) {
          navigator.clipboard.writeText(script);
        } else {
          const ta = document.createElement('textarea');
          ta.value = script;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); } finally { document.body.removeChild(ta); }
        }
        app.alerts.show({ type: 'success' }, t('webhook_script_copied', 'Script copiado.'));
      };

      return m('details.MailReplyAdmin-card.MailReplyAdmin-script', [
        m('summary.MailReplyAdmin-card-header', [
          m('h3', [m('i.fas.fa-code'), ' ', t('webhook_script_label', 'Script PHP standalone (alternativa ao provedor)')]),
          m('p.helpText', t('webhook_script_help', 'Clique para expandir. Cole no servidor de e-mail (não no Flarum).')),
        ]),
        m('.MailReplyAdmin-script-actions', [
          m('button.Button.Button--primary', { type: 'button', onclick: copyScript }, [
            m('i.fas.fa-copy'), ' ', t('webhook_script_copy', 'Copiar script'),
          ]),
          m('a.Button', {
            href: 'data:application/x-php;charset=utf-8,' + encodeURIComponent(script),
            download: 'forward-to-flarum.php',
          }, [m('i.fas.fa-download'), ' ', t('webhook_script_download', 'Baixar .php')]),
        ]),
        m('pre.MailReplyAdmin-script-code', m('code', script)),
        m('.MailReplyAdmin-script-steps', [
          m('strong', t('webhook_install_title', 'Passos no servidor de e-mail:')),
          m('ol', [
            m('li', [m('strong', t('webhook_install_step1_a', 'SSH no servidor')), ' ' + t('webhook_install_step1_b', 'e cole em') + ' ', m('code', '/usr/local/bin/forward-to-flarum.php')]),
            m('li', [m('code', 'chmod 755'), ' + ', m('code', 'chown root:mail')]),
            m('li', [t('webhook_install_step3_a', 'No painel:'), ' ', m('strong', t('webhook_install_step3_b', 'Forwarders → reply@... → Pipe')), ' + ', m('code', '/usr/local/bin/forward-to-flarum.php')]),
            m('li', [t('webhook_install_step4_a', 'Teste. Em'), ' ', m('code', '/var/log/exim/mainlog'), ' ' + t('webhook_install_step4_b', 'espere'), ' ', m('code', 'T=virtual_address_pipe ... Completed'), '.']),
          ]),
        ]),
      ]);
    }

    imapTestButton() {
      const state = (this._imapTestState ||= { loading: false, lastResult: null });

      const onTest = () => {
        if (state.loading) return;
        state.loading = true;
        state.lastResult = null;
        m.redraw();

        app
          .request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/mail-reply/test-imap',
            body: {
              imap_host: this.setting('ramon-mail-reply.imap_host')(),
              imap_port: this.setting('ramon-mail-reply.imap_port')(),
              imap_encryption: this.setting('ramon-mail-reply.imap_encryption')(),
              imap_username: this.setting('ramon-mail-reply.imap_username')(),
              imap_password: this.setting('ramon-mail-reply.imap_password')(),
              imap_mailbox: this.setting('ramon-mail-reply.imap_mailbox')(),
              imap_validate_cert: this.setting('ramon-mail-reply.imap_validate_cert')(),
            },
            errorHandler: () => {},
          })
          .then((res) => {
            state.lastResult = res;
            if (res?.ok) {
              app.alerts.show({ type: 'success' }, t('imap_test_ok', `IMAP OK — ${res.mailbox}, ${res.unseen} unseen.`));
            } else {
              app.alerts.show({ type: 'error' }, `${t('imap_test_failed', 'IMAP test failed')}: ${res?.message || 'unknown'}`);
            }
          })
          .catch((err) => {
            state.lastResult = { ok: false, message: String(err) };
            app.alerts.show({ type: 'error' }, t('imap_test_failed', 'IMAP test failed') + ': ' + String(err));
          })
          .finally(() => {
            state.loading = false;
            m.redraw();
          });
      };

      return [
        m('.MailReplyAdmin-card-header', [
          m('h3', t('imap_test_section', 'Testar conexão')),
          m('p.helpText', t('imap_test_help', 'Confere se o Flarum consegue conectar nas credenciais salvas.')),
        ]),
        m('button.Button.Button--primary', { type: 'button', disabled: state.loading, onclick: onTest }, [
          state.loading ? m('i.fas.fa-spinner.fa-spin') : m('i.fas.fa-plug'),
          ' ',
          state.loading ? t('imap_test_running', 'Testando…') : t('imap_test_button', 'Testar conexão IMAP'),
        ]),
        state.lastResult
          ? m('.MailReplyAdmin-testResult', { 'data-ok': state.lastResult.ok ? '1' : '0' },
              state.lastResult.ok
                ? `✓ ${state.lastResult.mailbox} — ${state.lastResult.unseen} unseen; capture: ${state.lastResult.expected_capture_pattern || '—'}`
                : `✗ ${state.lastResult.message || ''}`)
          : null,
      ];
    }
  }

  app.registry.for('ramon-mail-reply').registerPage(MailReplyPage);
});
