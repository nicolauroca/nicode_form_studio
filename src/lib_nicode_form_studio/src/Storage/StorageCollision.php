<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Storage;
/** Exclusive create failed because the key already exists; it is not owned by this write. */
final class StorageCollision extends \RuntimeException {}
