<script lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Textarea } from '@/components/ui/textarea';
    import { FileUpload } from '@/components/ui/file-upload';
    import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
    import { type BreadcrumbItem, type ValidationErrors } from '@/types';
    import { useForm } from '@inertiajs/svelte';
    import { FileText, Upload } from 'lucide-svelte';

    interface Props {
        errors?: ValidationErrors;
    }

    let { errors = {} }: Props = $props();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Transcripts',
            href: '/transcripts',
        },
        {
            title: 'Upload',
            href: '/transcripts/create',
        },
    ];

    const form = useForm({
        title: '',
        description: '',
        image: null as File | null,
    });

    let fileError = $state('');

    const handleFileChange = (event: CustomEvent<FileList | null>) => {
        fileError = '';
        const files = event.detail;
        if (files && files.length > 0) {
            $form.image = files[0];
        } else {
            $form.image = null;
        }
    };

    const handleFileError = (event: CustomEvent<string>) => {
        fileError = event.detail;
        $form.image = null;
    };

    const submit = (e: Event) => {
        e.preventDefault();

        if (!$form.image) {
            fileError = 'Please select an image to upload';
            return;
        }

        // Create FormData for file upload
        const formData = new FormData();
        formData.append('title', $form.title);
        if ($form.description) {
            formData.append('description', $form.description);
        }
        formData.append('image', $form.image);

        $form.post(route('transcripts.store'), {
            forceFormData: true,
            onSuccess: () => {
                // Will redirect to transcript show page automatically
            },
        });
    };
</script>

<svelte:head>
    <title>Upload Transcript</title>
</svelte:head>

<AppLayout {breadcrumbs}>
    <section class="mx-auto max-w-2xl p-4">
        <div class="mb-8">
            <div class="flex items-center space-x-3 mb-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10">
                    <FileText class="h-6 w-6 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Upload Transcript</h1>
                    <p class="text-muted-foreground">Upload a handwritten medical document to transcribe</p>
                </div>
            </div>
        </div>

        <Card class="border-2">
            <CardHeader>
                <CardTitle class="flex items-center space-x-2">
                    <Upload class="h-5 w-5" />
                    <span>Document Details</span>
                </CardTitle>
                <CardDescription>
                    Provide a title and description for your document, then upload the image to transcribe.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onsubmit={submit} class="space-y-6">
                    <!-- Title Field -->
                    <div class="space-y-2">
                        <Label for="title" class="text-sm font-medium">
                            Title <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="title"
                            bind:value={$form.title}
                            placeholder="e.g., Patient Visit - John Doe - March 2024"
                            required
                            class="border-2"
                            disabled={$form.processing}
                        />
                        <InputError message={errors.title?.[0]} />
                    </div>

                    <!-- Description Field -->
                    <div class="space-y-2">
                        <Label for="description" class="text-sm font-medium">
                            Description <span class="text-muted-foreground">(optional)</span>
                        </Label>
                        <Textarea
                            id="description"
                            bind:value={$form.description}
                            placeholder="Add any additional context about this document..."
                            rows={3}
                            class="border-2 resize-none"
                            disabled={$form.processing}
                        />
                        <InputError message={errors.description?.[0]} />
                    </div>

                    <!-- File Upload Field -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium">
                            Medical Document Image <span class="text-destructive">*</span>
                        </Label>
                        <FileUpload
                            accept="image/*"
                            maxSize={10}
                            on:change={handleFileChange}
                            on:error={handleFileError}
                            error={fileError || errors.image?.[0]}
                            class="border-2"
                        />
                        {#if fileError}
                            <InputError message={fileError} />
                        {:else if errors.image?.[0]}
                            <InputError message={errors.image[0]} />
                        {/if}
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end space-x-3 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={$form.processing}
                            onclick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={$form.processing || !$form.title || !$form.image}
                            class="min-w-32"
                        >
                            {#if $form.processing}
                                <div class="flex items-center space-x-2">
                                    <div class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></div>
                                    <span>Creating...</span>
                                </div>
                            {:else}
                                Upload Transcript
                            {/if}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Information Cards -->
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="text-base">Supported Formats</CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <ul class="space-y-1">
                        <li>JPEG, PNG, WebP images</li>
                        <li>Maximum file size: 10MB</li>
                        <li>High resolution recommended</li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="text-base">Processing Time</CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <ul class="space-y-1">
                        <li>Typically 30-60 seconds</li>
                        <li>Complex documents may take longer</li>
                        <li>You'll see real-time updates</li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </section>
</AppLayout>
