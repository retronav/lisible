<script lang="ts">
    import { cn } from "@/lib/utils.js";
    import { createEventDispatcher } from "svelte";
    import { Upload, X } from "lucide-svelte";
    import { Button } from "@/components/ui/button";

    interface Props {
        class?: string;
        accept?: string;
        maxSize?: number; // in MB
        multiple?: boolean;
        files?: FileList | null;
        error?: string;
    }

    let {
        class: className,
        accept = "image/*",
        maxSize = 10,
        multiple = false,
        files = $bindable(null),
        error
    }: Props = $props();

    const dispatch = createEventDispatcher<{
        change: FileList | null;
        error: string;
    }>();

    let dragActive = $state(false);
    let fileInput: HTMLInputElement;
    let previewUrls: string[] = $state([]);

    const validateFile = (file: File): string | null => {
        if (maxSize && file.size > maxSize * 1024 * 1024) {
            return `File size must be less than ${maxSize}MB`;
        }

        if (accept && !file.type.match(accept.replace('*', '.*'))) {
            return `File type not allowed. Accepted types: ${accept}`;
        }

        return null;
    };

    const handleFiles = (fileList: FileList | null) => {
        if (!fileList || fileList.length === 0) {
            files = null;
            previewUrls = [];
            return;
        }

        // Validate files
        for (let i = 0; i < fileList.length; i++) {
            const validationError = validateFile(fileList[i]);
            if (validationError) {
                dispatch('error', validationError);
                return;
            }
        }

        files = fileList;

        // Generate preview URLs for images
        previewUrls = [];
        for (let i = 0; i < fileList.length; i++) {
            const file = fileList[i];
            if (file.type.startsWith('image/')) {
                const url = URL.createObjectURL(file);
                previewUrls.push(url);
            }
        }

        dispatch('change', fileList);
    };

    const handleDragOver = (e: DragEvent) => {
        e.preventDefault();
        dragActive = true;
    };

    const handleDragLeave = (e: DragEvent) => {
        e.preventDefault();
        dragActive = false;
    };

    const handleDrop = (e: DragEvent) => {
        e.preventDefault();
        dragActive = false;

        if (e.dataTransfer?.files) {
            handleFiles(e.dataTransfer.files);
        }
    };

    const handleInputChange = (e: Event) => {
        const target = e.target as HTMLInputElement;
        handleFiles(target.files);
    };

    const removeFile = (index: number) => {
        if (!files) return;

        const dt = new DataTransfer();
        for (let i = 0; i < files.length; i++) {
            if (i !== index) {
                dt.items.add(files[i]);
            }
        }

        // Revoke the object URL to prevent memory leaks
        if (previewUrls[index]) {
            URL.revokeObjectURL(previewUrls[index]);
        }

        fileInput.files = dt.files;
        handleFiles(dt.files);
    };

    const openFilePicker = () => {
        fileInput.click();
    };

    // Cleanup object URLs on unmount
    $effect(() => {
        return () => {
            previewUrls.forEach(url => URL.revokeObjectURL(url));
        };
    });
</script>

<div class={cn("w-full", className)}>
    <!-- Hidden file input -->
    <input
        bind:this={fileInput}
        type="file"
        {accept}
        {multiple}
        onchange={handleInputChange}
        class="hidden"
    />

    <!-- Drop zone -->
    <div
        class={cn(
            "border-2 border-dashed rounded-lg p-6 transition-colors cursor-pointer",
            "hover:border-primary/50 hover:bg-primary/5",
            dragActive && "border-primary bg-primary/10",
            error && "border-destructive",
            "border-border"
        )}
        ondragover={handleDragOver}
        ondragleave={handleDragLeave}
        ondrop={handleDrop}
        onclick={openFilePicker}
        role="button"
        tabindex="0"
        onkeydown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openFilePicker();
            }
        }}
    >
        <div class="flex flex-col items-center text-center">
            <Upload class="h-10 w-10 text-muted-foreground mb-4" />
            <p class="text-sm font-medium text-foreground mb-2">
                Drop files here or click to browse
            </p>
            <p class="text-xs text-muted-foreground">
                {accept} files up to {maxSize}MB
            </p>
        </div>
    </div>

    <!-- File previews -->
    {#if files && files.length > 0}
        <div class="mt-4 space-y-2">
            {#each Array.from(files) as file, index}
                <div class="flex items-center justify-between p-3 bg-muted rounded-md">
                    <div class="flex items-center space-x-3">
                        {#if previewUrls[index]}
                            <img
                                src={previewUrls[index]}
                                alt="Preview"
                                class="h-10 w-10 object-cover rounded"
                            />
                        {/if}
                        <div>
                            <p class="text-sm font-medium">{file.name}</p>
                            <p class="text-xs text-muted-foreground">
                                {(file.size / 1024 / 1024).toFixed(2)} MB
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        onclick={() => removeFile(index)}
                        class="text-destructive hover:text-destructive"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            {/each}
        </div>
    {/if}
</div>
