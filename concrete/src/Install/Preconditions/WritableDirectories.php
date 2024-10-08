<?php

namespace Concrete\Core\Install\Preconditions;

use Concrete\Core\Install\PreconditionInterface;
use Concrete\Core\Install\PreconditionResult;
use Concrete\Core\System\SystemUser;
use Illuminate\Filesystem\Filesystem;

class WritableDirectories implements PreconditionInterface
{
    /**
     * The Filesystem to be used to check directories.
     *
     * @var Filesystem
     */
    protected $fs;

    /**
     * @var \Concrete\Core\System\SystemUser
     */
    protected $systemUser;

    /**
     * Initializes the instance.
     *
     * @param Filesystem $fs the Filesystem to be used to check directories
     */
    public function __construct(Filesystem $fs, SystemUser $systemUser)
    {
        $this->fs = $fs;
        $this->systemUser = $systemUser;
    }

    /**
     * {@inheritdoc}
     *
     * @see PreconditionInterface::getName()
     */
    public function getName()
    {
        return t('Writable Files and Configuration Directories');
    }

    /**
     * {@inheritdoc}
     *
     * @see PreconditionInterface::getUniqueIdentifier()
     */
    public function getUniqueIdentifier()
    {
        return 'writable_directories';
    }

    /**
     * {@inheritdoc}
     *
     * @see PreconditionInterface::isOptional()
     */
    public function isOptional()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @see PreconditionInterface::performCheck()
     */
    public function performCheck()
    {
        $notWritableDirectories = [];
        foreach ([
            DIR_CONFIG_SITE => DIRNAME_APPLICATION . '/' . DIRNAME_CONFIG . '/',
            DIR_FILES_UPLOADED_STANDARD => DIRNAME_APPLICATION . '/files/',
            DIR_PACKAGES => DIRNAME_PACKAGES . '/',
        ] as $path => $name) {
            if (!$this->fs->isDirectory($path) || !$this->fs->isWritable($path)) {
                $notWritableDirectories[] = $name;
            }
        }
        $result = new PreconditionResult();
        $numNotWritableDirectories = count($notWritableDirectories);
        if ($numNotWritableDirectories > 0) {
            $username = $this->systemUser->getCurrentUserName();
            if ($username === '') {
                $message = t2('This directory must exist and it must be writable by your web server:', 'These directories must exist and they must be writable by your web server:', $numNotWritableDirectories);
            } else {
                $message = t2('This directory must exist and it must be writable by your web server (which is running with the system user %2$s):', 'These directories must exist and they must be writable by your web server (which is running with the system user %2$s):', $numNotWritableDirectories, $username);
            }
            $message .= ' ' . \Punic\Misc::join($notWritableDirectories);
            $result
                ->setState(PreconditionResult::STATE_FAILED)
                ->setMessage($message)
            ;
        }

        return $result;
    }
}
