<?php

namespace Filament\Forms\Components;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\TemporaryUploadedFile;
use Throwable;

class BaseFileUpload extends Field
{
    protected array | Arrayable | Closure | null $acceptedFileTypes = null;

    protected bool | Closure $canReorder = false;

    protected bool | Closure $canPreview = true;

    protected string | Closure | null $directory = null;

    protected string | Closure | null $diskName = null;

    protected bool | Closure $isMultiple = false;

    protected int | Closure | null $maxSize = null;

    protected int | Closure | null $minSize = null;

    protected int | Closure | null $maxFiles = null;

    protected int | Closure | null $minFiles = null;

    protected bool | Closure $shouldPreserveFilenames = false;

    protected string | Closure $visibility = 'public';

    protected ?Closure $deleteUploadedFileUsing = null;

    protected ?Closure $getUploadedFileNameForStorageUsing = null;

    protected ?Closure $getUploadedFileUrlUsing = null;

    protected ?Closure $reorderUploadedFilesUsing = null;

    protected ?Closure $saveUploadedFileUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->afterStateHydrated(static function (BaseFileUpload $component, string | array | null $state): void {
            if (blank($state)) {
                $component->state([]);

                return;
            }

            $files = collect(Arr::wrap($state))
                ->filter(static fn (string $file) => blank($file) || $component->getDisk()->exists($file))
                ->mapWithKeys(static fn (string $file): array => [((string) Str::uuid()) => $file])
                ->toArray();

            $component->state($files);
        });

        $this->afterStateUpdated(static function (BaseFileUpload $component, $state) {
            if (blank($state)) {
                return;
            }

            if (is_array($state)) {
                return;
            }

            $component->state([(string) Str::uuid() => $state]);
        });

        $this->beforeStateDehydrated(static function (BaseFileUpload $component): void {
            $component->saveUploadedFiles();
        });

        $this->dehydrateStateUsing(static function (BaseFileUpload $component, ?array $state): string | array | null {
            $files = array_values($state ?? []);

            if ($component->isMultiple()) {
                return $files;
            }

            return $files[0] ?? null;
        });

        $this->getUploadedFileUrlUsing(static function (BaseFileUpload $component, string $file): ?string {
            /** @var FilesystemAdapter $storage */
            $storage = $component->getDisk();

            if (! $storage->exists($file)) {
                return null;
            }

            if ($storage->getVisibility($file) === 'private') {
                try {
                    return $storage->temporaryUrl(
                        $file,
                        now()->addMinutes(5),
                    );
                } catch (Throwable $exception) {
                    // This driver does not support creating temporary URLs.
                }
            }

            return $storage->url($file);
        });

        $this->getUploadedFileNameForStorageUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file) {
            return $component->shouldPreserveFilenames() ? $file->getClientOriginalName() : $file->getFilename();
        });

        $this->saveUploadedFileUsing(static function (BaseFileUpload $component, TemporaryUploadedFile $file): string {
            $storeMethod = $component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs';

            return $file->{$storeMethod}(
                $component->getDirectory(),
                $component->getUploadedFileNameForStorage($file),
                $component->getDiskName(),
            );
        });
    }

    public function callAfterStateUpdated(): static
    {
        if ($callback = $this->afterStateUpdated) {
            $state = $this->getState();

            $this->evaluate($callback, [
                'state' => $this->isMultiple() ? $state : Arr::first($state ?? []),
            ]);
        }

        return $this;
    }

    public function acceptedFileTypes(array | Arrayable | Closure $types): static
    {
        $this->acceptedFileTypes = $types;

        $this->rule(static function (BaseFileUpload $component) {
            $types = implode(',', ($component->getAcceptedFileTypes() ?? []));

            return "mimetypes:{$types}";
        });

        return $this;
    }

    public function directory(string | Closure | null $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function disk($name): static
    {
        $this->diskName = $name;

        return $this;
    }

    public function enableReordering(bool | Closure $condition = true): static
    {
        $this->canReorder = $condition;

        return $this;
    }

    public function disablePreview(bool | Closure $condition = false): static
    {
        $this->canPreview = $condition;

        return $this;
    }

    public function preserveFilenames(bool | Closure $condition = true): static
    {
        $this->shouldPreserveFilenames = $condition;

        return $this;
    }

    public function maxSize(int | Closure | null $size): static
    {
        $this->maxSize = $size;

        $this->rule(static function (BaseFileUpload $component): string {
            $size = $component->getMaxSize();

            return "max:{$size}";
        });

        return $this;
    }

    public function minSize(int | Closure | null $size): static
    {
        $this->minSize = $size;

        $this->rule(static function (BaseFileUpload $component): string {
            $size = $component->getMinSize();

            return "min:{$size}";
        });

        return $this;
    }

    public function maxFiles(int | Closure | null $count): static
    {
        $this->maxFiles = $count;

        return $this;
    }

    public function minFiles(int | Closure | null $count): static
    {
        $this->minFiles = $count;

        return $this;
    }

    public function multiple(bool | Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function visibility(string | Closure | null $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function deleteUploadedFileUsing(?Closure $callback): static
    {
        $this->deleteUploadedFileUsing = $callback;

        return $this;
    }

    public function getUploadedFileUrlUsing(?Closure $callback): static
    {
        $this->getUploadedFileUrlUsing = $callback;

        return $this;
    }

    public function reorderUploadedFilesUsing(?Closure $callback): static
    {
        $this->reorderUploadedFilesUsing = $callback;

        return $this;
    }

    public function saveUploadedFileUsing(?Closure $callback): static
    {
        $this->saveUploadedFileUsing = $callback;

        return $this;
    }

    public function canReorder(): bool
    {
        return $this->evaluate($this->canReorder);
    }

    public function canPreview(): bool
    {
        return $this->evaluate($this->canPreview);
    }

    public function getAcceptedFileTypes(): ?array
    {
        $types = $this->evaluate($this->acceptedFileTypes);

        if ($types instanceof Arrayable) {
            $types = $types->toArray();
        }

        return $types;
    }

    public function getDirectory(): ?string
    {
        return $this->evaluate($this->directory);
    }

    public function getDisk(): Filesystem
    {
        return Storage::disk($this->getDiskName());
    }

    public function getDiskName(): string
    {
        return $this->evaluate($this->diskName) ?? config('forms.default_filesystem_disk');
    }

    public function getMaxSize(): ?int
    {
        return $this->evaluate($this->maxSize);
    }

    public function getMinSize(): ?int
    {
        return $this->evaluate($this->minSize);
    }

    public function getVisibility(): string
    {
        return $this->evaluate($this->visibility);
    }

    public function shouldPreserveFilenames(): bool
    {
        return $this->evaluate($this->shouldPreserveFilenames);
    }

    public function getValidationRules(): array
    {
        $rules = [
            $this->getRequiredValidationRule(),
            'array',
        ];

        if (filled($count = $this->maxFiles)) {
            $rules[] = "max:{$count}";
        }

        if (filled($count = $this->minFiles)) {
            $rules[] = "min:{$count}";
        }

        $rules[] = function (string $attribute, array $value, Closure $fail): void {
            $files = array_filter($value, fn (TemporaryUploadedFile | string $file): bool => $file instanceof TemporaryUploadedFile);

            $name = $this->getName();

            $validator = Validator::make(
                data: [$name => $files],
                rules: ["{$name}.*" => array_merge(['file'], parent::getValidationRules())],
                customAttributes: ["{$name}.*" => $this->getValidationAttribute()],
            );

            if (! $validator->fails()) {
                return;
            }

            $fail($validator->errors()->first());
        };

        return $rules;
    }

    public function deleteUploadedFile(string $fileKey): static
    {
        $file = $this->removeUploadedFile($fileKey);

        if (blank($file)) {
            return $this;
        }

        $callback = $this->deleteUploadedFileUsing;

        if (! $callback) {
            return $this;
        }

        $this->evaluate($callback, [
            'file' => $file,
        ]);

        return $this;
    }

    public function removeUploadedFile(string $fileKey): string | TemporaryUploadedFile | null
    {
        $files = $this->getState();
        $file = $files[$fileKey] ?? null;

        if (! $file) {
            return null;
        }

        if ($file instanceof TemporaryUploadedFile) {
            $file->delete();
        }

        unset($files[$fileKey]);

        $this->state($files);

        return $file;
    }

    public function reorderUploadedFiles(array $fileKeys): void
    {
        if (! $this->canReorder) {
            return;
        }

        $fileKeys = array_flip($fileKeys);

        $state = collect($this->getState())
            ->sortBy(static fn ($file, $fileKey) => $fileKeys[$fileKey] ?? null) // $fileKey may not be present in $fileKeys if it was added to the state during the reorder call
            ->toArray();

        $this->state($state);
    }

    public function getUploadedFileUrls(): ?array
    {
        return collect($this->getState() ?? [])
            ->mapWithKeys(function (TemporaryUploadedFile | string $file, string $fileKey): array {
                if ($file instanceof TemporaryUploadedFile) {
                    return [$fileKey => null];
                }

                $callback = $this->getUploadedFileUrlUsing;

                if (! $callback) {
                    return [$fileKey => null];
                }

                $url = $this->evaluate($callback, [
                    'file' => $file,
                ]);

                return [$fileKey => ($url ?: null)];
            })
            ->toArray();
    }

    public function saveUploadedFiles(): void
    {
        if (blank($this->getState())) {
            $this->state([]);

            return;
        }

        if (! is_array($this->getState())) {
            $this->state([$this->getState()]);
        }

        $state = array_map(function (TemporaryUploadedFile | string $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                return $file;
            }

            $callback = $this->saveUploadedFileUsing;

            if (! $callback) {
                $file->delete();

                return $file;
            }

            $storedFile = $this->evaluate($callback, [
                'file' => $file,
            ]);

            $file->delete();

            return $storedFile;
        }, $this->getState());

        if ($this->canReorder && ($callback = $this->reorderUploadedFilesUsing)) {
            $state = $this->evaluate($callback, [
                'state' => $state,
            ]);
        }

        $this->state($state);
    }

    public function isMultiple(): bool
    {
        return $this->evaluate($this->isMultiple);
    }

    public function getUploadedFileNameForStorageUsing(Closure $callback): static
    {
        $this->getUploadedFileNameForStorageUsing = $callback;

        return $this;
    }

    public function getUploadedFileNameForStorage(TemporaryUploadedFile $file): string
    {
        return $this->evaluate($this->getUploadedFileNameForStorageUsing, [
            'file' => $file,
        ]);
    }
}
