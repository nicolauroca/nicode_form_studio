<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Security;
final class Permissions
{
    public const ALL = ['core.admin', 'core.options', 'core.manage', 'core.create', 'core.edit', 'core.edit.state', 'core.delete', 'formstudio.forms.manage', 'formstudio.forms.publish', 'formstudio.submissions.view', 'formstudio.submissions.manage', 'formstudio.submissions.export', 'formstudio.submissions.delete', 'formstudio.submissions.anonymize', 'formstudio.submissions.reindex', 'formstudio.submissions.retry', 'formstudio.submissions.view_sensitive', 'formstudio.resources.manage', 'formstudio.logs.view', 'formstudio.jobs.manage'];
}
