# Fieldmanager Revisions (Alpha)

A pre-alpha release of Fieldmanager Revisions.

**NOTE:** this will change and will not have long-term support.
This is an early iteration to serve as a POC and to get early feedback.

## Known Issues

* If you have a revision which intentionally deletes post meta, and you restore that revision, the meta will not be deleted. There's no way to discern between a revision whose meta is intentionally deleted and a revision whose meta simply wasn't stored (e.g. the post was updated by an external process and not through a "save" in the admin)
* Data is sanitized using `wp_kses_post()`. If your field uses custom sanitization or depends on having HTML stripped, your previews and restored revisions will not work as expected.
