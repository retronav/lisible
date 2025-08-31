<script lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import HeadingSmall from '@/components/HeadingSmall.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Textarea } from '@/components/ui/textarea';
    import { FileUpload } from '@/components/ui/file-upload';
    import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
    import { type BreadcrumbItem, type Transcript, type ValidationErrors } from '@/types';
    import { useForm } from '@inertiajs/svelte';
    import { FileText, Save, X } from 'lucide-svelte';

    interface Props {
        transcript: Transcript;
        errors?: ValidationErrors;
    }

    let { transcript, errors = {} }: Props = $props();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Transcripts',
            href: '/transcripts',
        },
        {
            title: transcript.title,
            href: `/transcripts/${transcript.id}`,
        },
        {
            title: 'Edit',
            href: `/transcripts/${transcript.id}/edit`,
        },
    ];

    const form = useForm({
        title: transcript.title,
        description: transcript.description || '',
        image: null as File | null,
    });

    let fileError = $state('');
    let willTriggerReprocessing = $state(false);

    const handleFileChange = (event: CustomEvent<FileList | null>) => {
        fileError = '';
        const files = event.detail;
        willTriggerReprocessing = !!(files && files.length > 0);
        if (files && files.length > 0) {
            $form.image = files[0];
        } else {
            $form.image = null;
            willTriggerReprocessing = false;
        }
    };

    const handleFileError = (event: CustomEvent<string>) => {
        fileError = event.detail;
        $form.image = null;
        willTriggerReprocessing = false;
    };

    const submit = (e: Event) => {
        e.preventDefault();

        // Create FormData if there's a file, otherwise use regular form data
        if ($form.image) {
            $form.post(route('transcripts.update', transcript.id), {
                forceFormData: true,
                method: 'put',
                onSuccess: () => {
                    // Will redirect to transcript show page automatically
                },
            });
        } else {
            $form.put(route('transcripts.update', transcript.id), {
                onSuccess: () => {
                    // Will redirect to transcript show page automatically
                },
            });
        }
    };

    const isProcessing = transcript.status === 'processing';
</script>

<svelte:head>
    <title>Edit {transcript.title}</title>
</svelte:head>

<AppLayout {breadcrumbs}>
    <section class="mx-auto max-w-2xl p-4">
        <div class="mb-8">
            <div class="flex items-center space-x-3 mb-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10">
                    <FileText class="h-6 w-6 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Edit Transcript</h1>
                    <p class="text-muted-foreground">Update the transcript details or upload a new image</p>
                </div>
            </div>
        </div>

        {#if isProcessing}
            <Card class="mb-6 border-amber-200 bg-amber-50">
                <CardContent class="p-4">
                    <div class="flex items-center space-x-3 text-amber-800">
                        <div class="h-4 w-4 animate-spin rounded-full border-2 border-amber-600 border-t-transparent"></div>
                        <div>
                            <p class="font-medium">Transcript is currently being processed</p>
                            <p class="text-sm text-amber-700">Editing is disabled while processing is in progress</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        {/if}

        <Card class="border-2">
            <CardHeader>
                <CardTitle class="flex items-center space-x-2">
                    <Save class="h-5 w-5" />
                    <span>Update Document</span>
                </CardTitle>
                <CardDescription>
                    Modify the transcript details. Upload a new image to trigger re-transcription.
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
                            disabled={$form.processing || isProcessing}
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
                            disabled={$form.processing || isProcessing}
                        />
                        <InputError message={errors.description?.[0]} />
                    </div>

                    <!-- Current Image Display -->
                    {#if transcript.image}
                        <div class="space-y-2">
                            <Label class="text-sm font-medium">Current Image</Label>
                            <div class="border-2 rounded-md p-4">
                                <img
                                    src={transcript.image}
                                    alt="Current document"
                                    class="max-w-full h-auto max-h-64 rounded border mx-auto"
                                />
                            </div>
                        </div>
                    {/if}

                    <!-- File Upload Field -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium">
                            Replace Image <span class="text-muted-foreground">(optional)</span>
                        </Label>
                        <FileUpload
                            accept="image/*"
                            maxSize={10}
                            on:change={handleFileChange}
                            on:error={handleFileError}
                            error={fileError || errors.image?.[0]}
                            class="border-2"
                        />
                        {#if willTriggerReprocessing}
                            <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
                                <strong>Note:</strong> Uploading a new image will trigger re-transcription.
                                The current transcribed content will be replaced.
                            </div>
                        {/if}
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
                            <X class="h-4 w-4 mr-2" />
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={$form.processing || isProcessing || !$form.title}
                            class="min-w-32"
                        >
                            {#if $form.processing}
                                <div class="flex items-center space-x-2">
                                    <div class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></div>
                                    <span>Saving...</span>
                                </div>
                            {:else}
                                <Save class="h-4 w-4 mr-2" />
                                Save Changes
                            {/if}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Information Card -->
        <Card class="mt-6">
            <CardHeader class="pb-3">
                <CardTitle class="text-base">Important Notes</CardTitle>
            </CardHeader>
            <CardContent class="text-sm text-muted-foreground">
                <ul class="space-y-1">
                    <li>• Title and description can be updated without affecting the transcription</li>
                    <li>• Uploading a new image will start the transcription process over</li>
                    <li>• Editing is disabled while a transcription is being processed</li>
                    <li>• Changes are saved immediately when you submit the form</li>
                </ul>
            </CardContent>
        </Card>
    </section>
</AppLayout>
