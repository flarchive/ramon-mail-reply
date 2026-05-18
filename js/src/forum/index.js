import { extend } from 'flarum/common/extend';
import Model from 'flarum/common/Model';
import Post from 'flarum/common/models/Post';
import CommentPost from 'flarum/forum/components/CommentPost';

app.initializers.add('ramon-mail-reply', () => {
  // Expose the new read-only attribute on the Post model.
  Post.prototype.viaMailReply = Model.attribute('viaMailReply');

  // Append a small badge to the post header when applicable.
  extend(CommentPost.prototype, 'headerItems', function (items) {
    const post = this.attrs.post;

    if (!post?.viaMailReply?.()) return;
    if (!app.forum.attribute('mailReply.showBadge')) return;

    const label = app.translator.trans('ramon-mail-reply.forum.via_mail', {
      fallback: 'via email',
    });

    // Priority 95 coloca o badge logo após o username (core usa 100
    // pra username + 90 pra cardLink). Antes era 50 → ficava ao lado
    // do timestamp, longe do nome.
    items.add(
      'mailReplyBadge',
      m(
        'span.MailReplyBadge',
        { title: label, 'aria-label': label },
        [m('i.icon.fas.fa-envelope', { 'aria-hidden': 'true' }), m('span.MailReplyBadge-text', label)],
      ),
      95,
    );
  });
});
