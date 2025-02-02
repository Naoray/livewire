<?php

namespace Livewire\Features\SupportFileUploads;

use Illuminate\Support\Arr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

class TemporaryUploadedFile extends UploadedFile
{
    /**
     * 255 : max length
     * - 30 : hash
     * - 5 : -meta prefix
     * - 1 : meta '-' suffix
     * - 5 : extension
     * - 159 * 4/3 : max base64 encoded filename length
     * = 2 : a small buffer
     */
    public const FILENAME_TRUNCATION_MAX_LENGTH = 159;
    public const FILENAME_TRUNCATION_PREFIX = '[truncated]';

    protected $disk;
    protected $storage;
    protected $path;

    public function __construct($path, $disk)
    {
        $this->disk = $disk;
        $this->storage = Storage::disk($this->disk);
        $this->path = FileUploadConfiguration::path($path, false);

        $tmpFile = tmpfile();

        parent::__construct(stream_get_meta_data($tmpFile)['uri'], $this->path);

        // While running tests, update the last modified timestamp to the current
        // Carbon timestamp (which respects time traveling), because otherwise
        // cleanupOldUploads() will mess up with the filesystem...
        if (app()->runningUnitTests()) {
            @touch($this->path(), now()->timestamp);
        }
    }

    public function getPath(): string
    {
        return $this->storage->path(FileUploadConfiguration::directory());
    }

    public function isValid(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        if (app()->runningUnitTests() && str($this->getFilename())->contains('-size=')) {
            return (int) str($this->getFilename())->between('-size=', '.')->__toString();
        }

        return (int) $this->storage->size($this->path);
    }

    public function getMimeType(): string
    {
        if (app()->runningUnitTests() && str($this->getFilename())->contains('-mimeType=')) {
            $escapedMimeType = str($this->getFilename())->between('-mimeType=', '-');

            // MimeTypes contain slashes, but we replaced them with underscores in `SupportTesting\Testable`
            // to ensure the filename is valid, so we now need to revert that.
            return (string) $escapedMimeType->replace('_', '/');
        }

        $mimeType = $this->storage->mimeType($this->path);

        // Flysystem V2.0+ removed guess mimeType from extension support, so it has been re-added back
        // in here to ensure the correct mimeType is returned when using faked files in tests
        if (in_array($mimeType, ['application/octet-stream', 'inode/x-empty', 'application/x-empty'])) {
            $detector = new FinfoMimeTypeDetector();

            $mimeType = $detector->detectMimeTypeFromPath($this->path) ?: 'text/plain';
        }

        return $mimeType;
    }

    public function getFilename(): string
    {
        return $this->getName($this->path);
    }

    public function getRealPath(): string
    {
        return $this->storage->path($this->path);
    }

    public function getPathname(): string
    {
        return $this->getRealPath();
    }

    public function getClientOriginalName(): string
    {
        return $this->extractOriginalNameFromFilePath($this->path);
    }

    public function dimensions()
    {
        stream_copy_to_stream($this->storage->readStream($this->path), $tmpFile = tmpfile());

        return @getimagesize(stream_get_meta_data($tmpFile)['uri']);
    }

    public function temporaryUrl()
    {
        if (!$this->isPreviewable()) {
            throw new FileNotPreviewableException($this);
        }

        if ((FileUploadConfiguration::isUsingS3() or FileUploadConfiguration::isUsingGCS()) && ! app()->runningUnitTests()) {
            return $this->storage->temporaryUrl(
                $this->path,
                now()->addDay()->endOfHour(),
                ['ResponseContentDisposition' => 'attachment; filename="' . urlencode($this->getClientOriginalName()) . '"']
            );
        }

        if (method_exists($this->storage->getAdapter(), 'getTemporaryUrl')) {
            // This will throw an error because it's not used with S3.
            return $this->storage->temporaryUrl($this->path, now()->addDay());
        }

        return URL::temporarySignedRoute(
            'livewire.preview-file',
            now()->addMinutes(30)->endOfHour(),
            ['filename' => $this->getFilename()]
        );
    }

    public function isPreviewable()
    {
        $supportedPreviewTypes = config('livewire.temporary_file_upload.preview_mimes', [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ]);

        return in_array($this->guessExtension(),  $supportedPreviewTypes);
    }

    public function readStream()
    {
        return $this->storage->readStream($this->path);
    }

    public function exists()
    {
        return $this->storage->exists($this->path);
    }

    public function get()
    {
        return $this->storage->get($this->path);
    }

    public function delete()
    {
        return $this->storage->delete($this->path);
    }

    public function storeAs($path, $name = null, $options = [])
    {
        $options = $this->parseOptions($options);

        $disk = Arr::pull($options, 'disk') ?: $this->disk;

        $newPath = trim($path.'/'.$name, '/');

        Storage::disk($disk)->put(
            $newPath, $this->storage->readStream($this->path), $options
        );

        return $newPath;
    }

    public static function fileNameRequiresTruncation($filename)
    {
        return str($filename)->length() > TemporaryUploadedFile::FILENAME_TRUNCATION_MAX_LENGTH;
    }

    public static function generateHashNameWithOriginalNameEmbedded($file)
    {
        $originalFilename = $file->getClientOriginalName();

        if (static::fileNameRequiresTruncation($originalFilename)) {
            $originalFilename = str(TemporaryUploadedFile::FILENAME_TRUNCATION_PREFIX)
                ->append($file->hashName())
                ->toString();
        }

        $hash = str()->random(30);
        $meta = str('-meta' . base64_encode($originalFilename) . '-')->replace('/', '_');
        $extension = '.' . $file->getClientOriginalExtension();

        return $hash . $meta . $extension;
    }

    public function hashName($path = null)
    {
        if (app()->runningUnitTests() && str($this->getFilename())->contains('-hash=')) {
            return str($this->getFilename())->between('-hash=', '-mimeType')->value();
        }

        return parent::hashName($path);
    }

    public function extractOriginalNameFromFilePath($path)
    {
        if (app()->runningUnitTests() && str($filename = $this->getFilename())->contains('-hash=')) {
            $path = str($filename)
                ->replace([
                    '-hash=' . $this->hashName(),
                    '-mimeType=' . str($this->getMimeType())->replace('/', '_'),
                    '-size=' . $this->getSize(),
                ], '')
                ->value();
        }

        $decodedFileName = base64_decode(head(explode('-', last(explode('-meta', str($path)->replace('_', '/'))))));

        if (!str($decodedFileName)->startsWith(TemporaryUploadedFile::FILENAME_TRUNCATION_PREFIX)) {
            return $decodedFileName;
        }

        $fileBasename = pathinfo($path, PATHINFO_BASENAME);
        $metaFilePath = FileUploadConfiguration::metaPath($fileBasename);

        return FileUploadConfiguration::storage()->exists($metaFilePath)
            ? FileUploadConfiguration::storage()->get($metaFilePath)
            : $decodedFileName;
    }

    public static function createFromLivewire($filePath)
    {
        return new static($filePath, FileUploadConfiguration::disk());
    }

    public static function canUnserialize($subject)
    {
        if (is_string($subject)) {
            return (string) str($subject)->startsWith(['livewire-file:', 'livewire-files:']);
        }

        if (is_array($subject)) {
            return collect($subject)->contains(function ($value) {
                return static::canUnserialize($value);
            });
        }

        return false;
    }

    public static function unserializeFromLivewireRequest($subject)
    {
        if (is_string($subject)) {
            if (str($subject)->startsWith('livewire-file:')) {
                return static::createFromLivewire(str($subject)->after('livewire-file:'));
            }

            if (str($subject)->startsWith('livewire-files:')) {
                $paths = json_decode(str($subject)->after('livewire-files:'), true);

                return collect($paths)->map(function ($path) {
                    return static::createFromLivewire($path);
                })->toArray();
            }
        }

        if (is_array($subject)) {
            foreach ($subject as $key => $value) {
                $subject[$key] =  static::unserializeFromLivewireRequest($value);
            }
        }

        return $subject;
    }

    public function serializeForLivewireResponse()
    {
        return 'livewire-file:'.$this->getFilename();
    }

    public static function serializeMultipleForLivewireResponse($files)
    {
        return 'livewire-files:'.json_encode(collect($files)->map->getFilename());
    }
}
