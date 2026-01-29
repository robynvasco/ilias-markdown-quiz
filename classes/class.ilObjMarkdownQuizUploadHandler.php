<?php
declare(strict_types=1);

use ILIAS\FileUpload\Handler\AbstractCtrlAwareIRSSUploadHandler;
use ILIAS\ResourceStorage\Stakeholder\ResourceStakeholder;

/**
 * Upload handler for MarkdownQuiz PDF files
 * @ilCtrl_isCalledBy ilObjMarkdownQuizUploadHandler: ilObjMarkdownQuizGUI
 */
class ilObjMarkdownQuizUploadHandler extends AbstractCtrlAwareIRSSUploadHandler
{
    protected function getStakeholder(): ResourceStakeholder
    {
        return new ilObjMarkdownQuizStakeholder();
    }

    protected function getClassPath(): array
    {
        return [self::class];
    }

    public function supportsChunkedUploads(): bool
    {
        return false;
    }
}
