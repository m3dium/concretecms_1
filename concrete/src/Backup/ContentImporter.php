<?php
namespace Concrete\Core\Backup;

use Concrete\Core\Backup\ContentImporter\Importer\Routine\SpecifiableHomePageRoutineInterface;
use Concrete\Core\Backup\ContentImporter\Importer\Routine\SpecifiableImportModeRoutineInterface;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\File;
use Concrete\Core\File\Importer;
use Concrete\Core\Page\Type\Composer\FormLayoutSetControl;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Core;

class ContentImporter
{

    public const IMPORT_MODE_INSTALL = 'install';
    public const IMPORT_MODE_UPGRADE = 'upgrade';

    protected static $mcBlockIDs = array();
    protected static $ptComposerOutputControlIDs = array();
    protected $home;
    protected $importMode = self::IMPORT_MODE_INSTALL;

    /**
     * @param string $importMode - a constant mapping to self::IMPORT_MODE_INSTALL or self::IMPORT_MODE_UPGRADE
     * If upgrade is specified, any routines will work off existing database schema if applicable (note: not all
     * CIF elements always support upgrade context.). If INSTALL is specified, we use empty database schemas for
     * improved performance. For backward compatibility, UPGRADE is the default.
     */
    public function __construct(string $importMode = self::IMPORT_MODE_UPGRADE)
    {
        $this->importMode = $importMode;
    }

    public function setImportMode(string $importMode): void
    {
        $this->importMode = $importMode;
    }

    public function importContentFile($file)
    {
        $sx = simplexml_load_file($file);
        $this->import($sx);
    }

    public function setHomePage($c)
    {
        $this->home = $c;
    }

    public function importContentString($string)
    {
        $sx = simplexml_load_string($string);
        $this->import($sx);
    }

    public function importXml(\SimpleXMLElement $xml)
    {
        $this->import($xml);
    }

    protected function import(\SimpleXMLElement $element)
    {
        $manager = Core::make('import/item/manager');
        foreach ($manager->getImporterRoutines() as $routine) {
            if (isset($this->home) && $routine instanceof SpecifiableHomePageRoutineInterface) {
                $home = \Page::getByID($this->home->getCollectionID()); // we always need the most recent version.
                $routine->setHomePage($home);
            }
            if ($routine instanceof SpecifiableImportModeRoutineInterface) {
                $routine->setImportMode($this->importMode);
            }
            $routine->import($element);
            if (isset($this->home) && $routine instanceof SpecifiableHomePageRoutineInterface) {
                // Unset the home page on each item post import
                $routine->setHomePage(null);
            }
        }
    }

    public static function getMasterCollectionTemporaryBlockIDs()
    {
        return self::$mcBlockIDs;
    }

    public static function addMasterCollectionBlockID($b, $id)
    {
        self::$mcBlockIDs[$b->getBlockID()] = $id;
    }

    public static function getMasterCollectionTemporaryBlockID($b)
    {
        if (isset(self::$mcBlockIDs[$b->getBlockID()])) {
            return self::$mcBlockIDs[$b->getBlockID()];
        }
    }

    public static function addPageTypeComposerOutputControlID(FormLayoutSetControl $control, $id)
    {
        self::$ptComposerOutputControlIDs[$id] = $control->getPageTypeComposerFormLayoutSetControlID();
    }

    public static function getPageTypeComposerFormLayoutSetControlFromTemporaryID($id)
    {
        if (isset(self::$ptComposerOutputControlIDs[$id])) {
            return self::$ptComposerOutputControlIDs[$id];
        }
    }

    public function importFiles($fromPath, $computeThumbnails = true)
    {
        $fh = new Importer();

        if (!$computeThumbnails) {
            $fh->setRescanThumbnailsOnImport(false);
            $helper = Core::make('helper/file');
        }
        $contents = Core::make('helper/file')->getDirectoryContents($fromPath);
        foreach ($contents as $filename) {
            if (!is_dir($fromPath . '/' . $filename)) {
                if (preg_match("/([0-9]{12}]*)\_(.*)/", $filename, $matches)) {
                    // a prefix is already present in the filename.
                    $fvPrefix = $matches[1];
                    $fvFilename = $matches[2];
                } else {
                    $fvPrefix = null;
                    $fvFilename = $filename;
                }

                $fv = $fh->import($fromPath . '/' . $filename, $fvFilename, null, $fvPrefix);
                if (!is_object($fv)) {
                    throw new \Exception(Importer::getErrorMessage($fv));
                }
                if (!$computeThumbnails) {
                    $types = \Concrete\Core\File\Image\Thumbnail\Type\Type::getVersionList();
                    foreach ($types as $type) {
                        // since we provide the thumbnails, we're going to get a list of thumbnail types
                        // and loop through them, assigning them to all the files.
                        $thumbnailPath = $fromPath . '/' . $type->getHandle() . '/' . $filename;
                        if (file_exists($thumbnailPath)) {
                            $fv->importThumbnail($type, $thumbnailPath);
                        }
                    }
                }
            }
        }
    }

    /**
     * Moves files with a particular filename to a particular folder.
     *
     * @param array $fileNames
     * @param string|FileFolder $folderName
     */
    public function moveFilesByName(array $fileNames, $folder)
    {
        $app = app();
        if (is_string($folder)) {
            $folder = FileFolder::getNodeByName($folder);
        }
        if ($folder) {
            $db = $app->make(Connection::class);
            foreach ($fileNames as $name) {
                $fID = $db->fetchOne('select fID from FileVersions where fvFilename = ?', [$name]);
                if ($fID) {
                    $file = File::getByID($fID);
                    if ($file) {
                        $fileNode = $file->getFileNodeObject();
                        if ($fileNode) {
                            $fileNode->move($folder);
                        }
                    }
                }
            }
        }
    }

    /**
     * Deletes files with a particular filename.
     *
     * Note: only use this in highly controlled environments, as in resetting sample content.
     *
     * @param array $fileNames
     * @param string|FileFolder $folderName
     */
    public function deleteFilesByName(array $fileNames)
    {
        $app = app();
        $db = $app->make(Connection::class);
        foreach ($fileNames as $name) {
            $fID = $db->fetchOne('select fID from FileVersions where fvFilename = ?', [$name]);
            if ($fID) {
                $file = File::getByID($fID);
                if ($file) {
                    $file->delete();
                }
            }
        }
    }

}
