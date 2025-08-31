<script lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
    import { Badge } from '@/components/ui/badge';
    import { type BreadcrumbItem, type Transcript } from '@/types';
    import { Link, router } from '@inertiajs/svelte';
    import {
        FileText,
        Edit,
        Trash2,
        Copy,
        RefreshCw,
        Clock,
        CheckCircle,
        XCircle,
        Loader,
        Calendar,
        User,
        Pill,
        Activity,
        FileSearch,
        Stethoscope,
        ClipboardList,
        UserCheck,
    } from 'lucide-svelte';
    import { onMount, onDestroy } from 'svelte';

    interface Props {
        transcript: Transcript;
    }

    let { transcript }: Props = $props();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Transcripts',
            href: '/transcripts',
        },
        {
            title: transcript.title,
            href: `/transcripts/${transcript.id}`,
        },
    ];

    let pollInterval: ReturnType<typeof setInterval> | null = null;
    let showCopySuccess = $state(false);

    const statusConfig = {
        pending: {
            label: 'Pending',
            icon: Clock,
            class: 'bg-yellow-100 text-yellow-800 border-yellow-200',
            description: 'Transcript is queued for processing',
        },
        processing: {
            label: 'Processing',
            icon: Loader,
            class: 'bg-blue-100 text-blue-800 border-blue-200',
            description: 'AI is analyzing your document',
        },
        completed: {
            label: 'Completed',
            icon: CheckCircle,
            class: 'bg-green-100 text-green-800 border-green-200',
            description: 'Transcription completed successfully',
        },
        failed: {
            label: 'Failed',
            icon: XCircle,
            class: 'bg-red-100 text-red-800 border-red-200',
            description: 'Transcription failed - click retry to try again',
        },
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const getStatusIcon = () => {
        return statusConfig[transcript.status]?.icon || Clock;
    };

    const getStatusClass = () => {
        return statusConfig[transcript.status]?.class || 'bg-gray-100 text-gray-800';
    };

    const pollStatus = async () => {
        try {
            const response = await fetch(`/transcripts/${transcript.id}/status`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.ok) {
                const data = await response.json();

                // Update transcript with new data
                Object.assign(transcript, data.transcript);

                // Stop polling if completed or failed
                if (transcript.status === 'completed' || transcript.status === 'failed') {
                    if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }

                    // Reload page to get full updated data
                    router.reload({ only: ['transcript'] });
                }
            }
        } catch (error) {
            console.error('Error polling status:', error);
        }
    };

    const retryTranscription = () => {
        router.post(
            `/transcripts/${transcript.id}/retry`,
            {},
            {
                onSuccess: () => {
                    // Start polling again after retry
                    startPolling();
                },
            },
        );
    };

    const deleteTranscript = () => {
        if (confirm('Are you sure you want to delete this transcript? This action cannot be undone.')) {
            router.delete(`/transcripts/${transcript.id}`, {
                onSuccess: () => {
                    router.visit('/transcripts');
                },
            });
        }
    };

    const copyToClipboard = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            showCopySuccess = true;
            setTimeout(() => {
                showCopySuccess = false;
            }, 2000);
        } catch (error) {
            console.error('Failed to copy:', error);
        }
    };

    const startPolling = () => {
        if (transcript.status === 'pending' || transcript.status === 'processing') {
            pollInterval = setInterval(pollStatus, 2000); // Poll every 2 seconds
        }
    };

    onMount(() => {
        startPolling();
    });

    onDestroy(() => {
        if (pollInterval) {
            clearInterval(pollInterval);
        }
    });

    // Reactive statement to restart polling if status changes back to pending/processing
    $effect(() => {
        if (transcript.status === 'pending' || transcript.status === 'processing') {
            if (!pollInterval) {
                startPolling();
            }
        } else {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
    });
</script>

<svelte:head>
    <title>{transcript.title}</title>
</svelte:head>

<AppLayout {breadcrumbs}>
    <!-- Header Section -->
    <div class="mb-8 p-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10">
                    <FileText class="h-6 w-6 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">{transcript.title}</h1>
                    <p class="text-muted-foreground">
                        Created {formatDate(transcript.created_at)}
                    </p>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                {#if transcript.status !== 'processing'}
                    <Link href={`/transcripts/${transcript.id}/edit`}>
                        <Button variant="outline" class="border-2">
                            <Edit class="inline" />
                            Edit
                        </Button>
                    </Link>
                {/if}
                <Button variant="destructive" onclick={deleteTranscript} class="border-2">
                    <Trash2 class="inline" />
                    Delete
                </Button>
            </div>
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-2 p-4">
        <!-- Left Column: Status and Metadata -->
        <div class="space-y-6">
            <!-- Status Card -->
            <Card class="border-2">
                <CardHeader>
                    <div class="flex items-center space-x-3">
                        {#if transcript.status === 'pending'}
                            <Clock class={`h-5 w-5`} />
                        {:else if transcript.status === 'processing'}
                            <Loader class={`h-5 w-5 animate-spin`} />
                        {:else if transcript.status === 'completed'}
                            <CheckCircle class={`h-5 w-5`} />
                        {:else if transcript.status === 'failed'}
                            <XCircle class={`h-5 w-5`} />
                        {:else}
                            <Clock class={`h-5 w-5`} />
                        {/if}
                        <div>
                            <CardTitle>Status</CardTitle>
                            <CardDescription>
                                {statusConfig[transcript.status]?.description || 'Status unknown'}
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <Badge class={`border text-sm ${getStatusClass()}`}>
                            {#if transcript.status === 'pending'}
                                <Clock class="h-3 w-3 mr-1" />
                            {:else if transcript.status === 'processing'}
                                <Loader class="h-3 w-3 mr-1 animate-spin" />
                            {:else if transcript.status === 'completed'}
                                <CheckCircle class="h-3 w-3 mr-1" />
                            {:else if transcript.status === 'failed'}
                                <XCircle class="h-3 w-3 mr-1" />
                            {:else}
                                <Clock class="h-3 w-3 mr-1" />
                            {/if}
                            {statusConfig[transcript.status]?.label || transcript.status}
                        </Badge>

                        {#if transcript.status === 'failed'}
                            <Button onclick={retryTranscription} size="sm" class="border-2">
                                <RefreshCw class="inline" />
                                Retry
                            </Button>
                        {/if}
                    </div>

                    {#if transcript.processed_at}
                        <div class="mt-4 text-sm text-muted-foreground">
                            <Calendar class="h-4 w-4 inline mr-2" />
                            Processed {formatDate(transcript.processed_at)}
                        </div>
                    {/if}

                    {#if transcript.error_message}
                        <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-800">
                            <strong>Error:</strong>
                            {transcript.error_message}
                        </div>
                    {/if}
                </CardContent>
            </Card>

            <!-- Document Info -->
            <Card class="border-2">
                <CardHeader>
                    <CardTitle>Document Information</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div>
                        <div class="text-sm font-medium text-muted-foreground mb-1">Title</div>
                        <div class="text-sm">{transcript.title}</div>
                    </div>

                    {#if transcript.description}
                        <div>
                            <div class="text-sm font-medium text-muted-foreground mb-1">Description</div>
                            <div class="text-sm text-muted-foreground">{transcript.description}</div>
                        </div>
                    {/if}

                    <div>
                        <div class="text-sm font-medium text-muted-foreground mb-1">Created</div>
                        <div class="text-sm">{formatDate(transcript.created_at)}</div>
                    </div>
                </CardContent>
            </Card>

            <!-- Original Image -->
            {#if transcript.image}
                <Card class="border-2">
                    <CardHeader>
                        <CardTitle>Original Document</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <img src={transcript.image} alt="Original document" class="w-full rounded-md border" />
                    </CardContent>
                </Card>
            {/if}
        </div>

        <!-- Right Column: Transcribed Content -->
        <div class="space-y-6">
            {#if transcript.status === 'completed' && transcript.transcript}
                {@const data = transcript.transcript}

                <!-- Patient Information -->
                <Card class="border-2">
                    <CardHeader>
                        <CardTitle class="flex items-center space-x-2">
                            <User class="h-5 w-5" />
                            <span>Patient Information</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-sm font-medium text-muted-foreground">Name</div>
                                <div class="text-sm">{data.patient.name}</div>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-muted-foreground">Age</div>
                                <div class="text-sm">{data.patient.age} years</div>
                            </div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-muted-foreground">Gender</div>
                            <div class="text-sm">{data.patient.gender}</div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-muted-foreground">Visit Date</div>
                            <div class="text-sm">{new Date(data.date).toLocaleDateString()}</div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Prescriptions -->
                {#if data.prescriptions && data.prescriptions.length > 0}
                    <Card class="border-2">
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle class="flex items-center space-x-2">
                                    <Pill class="h-5 w-5" />
                                    <span>Prescriptions</span>
                                </CardTitle>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onclick={() =>
                                        copyToClipboard(
                                            data.prescriptions
                                                .map((p) => `${p.drug_name} - ${p.dosage} ${p.route} ${p.frequency} for ${p.duration}`)
                                                .join('\n'),
                                        )}
                                >
                                    {#if showCopySuccess}
                                        <CheckCircle class="inline" />
                                        Copied!
                                    {:else}
                                        <Copy class="inline" />
                                        Copy
                                    {/if}
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-4">
                                {#each data.prescriptions as prescription, index}
                                    <div class="border-l-2 border-primary pl-4">
                                        <div class="font-medium text-sm">{prescription.drug_name}</div>
                                        <div class="text-xs text-muted-foreground mt-1 space-y-1">
                                            <div>Dosage: {prescription.dosage}</div>
                                            <div>Route: {prescription.route}</div>
                                            <div>Frequency: {prescription.frequency}</div>
                                            <div>Duration: {prescription.duration}</div>
                                            {#if prescription.notes}
                                                <div>Notes: {prescription.notes}</div>
                                            {/if}
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        </CardContent>
                    </Card>
                {/if}

                <!-- Diagnoses -->
                {#if data.diagnoses && data.diagnoses.length > 0}
                    <Card class="border-2">
                        <CardHeader>
                            <CardTitle class="flex items-center space-x-2">
                                <Stethoscope class="h-5 w-5" />
                                <span>Diagnoses</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-3">
                                {#each data.diagnoses as diagnosis}
                                    <div class="border-l-2 border-blue-500 pl-4">
                                        <div class="font-medium text-sm">{diagnosis.condition}</div>
                                        {#if diagnosis.notes}
                                            <div class="text-xs text-muted-foreground mt-1">{diagnosis.notes}</div>
                                        {/if}
                                    </div>
                                {/each}
                            </div>
                        </CardContent>
                    </Card>
                {/if}

                <!-- Tests -->
                {#if data.tests && data.tests.length > 0}
                    <Card class="border-2">
                        <CardHeader>
                            <CardTitle class="flex items-center space-x-2">
                                <Activity class="h-5 w-5" />
                                <span>Tests & Results</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-3">
                                {#each data.tests as test}
                                    <div class="border-l-2 border-green-500 pl-4">
                                        <div class="font-medium text-sm">{test.test_name}</div>
                                        <div class="text-xs text-muted-foreground mt-1 space-y-1">
                                            {#if test.result}
                                                <div>Result: {test.result}</div>
                                            {/if}
                                            {#if test.normal_range}
                                                <div>Normal Range: {test.normal_range}</div>
                                            {/if}
                                            {#if test.notes}
                                                <div>Notes: {test.notes}</div>
                                            {/if}
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        </CardContent>
                    </Card>
                {/if}

                <!-- Observations -->
                {#if data.observations && data.observations.length > 0}
                    <Card class="border-2">
                        <CardHeader>
                            <CardTitle class="flex items-center space-x-2">
                                <FileSearch class="h-5 w-5" />
                                <span>Clinical Observations</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                {#each data.observations as observation}
                                    <div class="text-sm p-2 bg-muted rounded">
                                        {observation}
                                    </div>
                                {/each}
                            </div>
                        </CardContent>
                    </Card>
                {/if}

                <!-- Instructions -->
                {#if data.instructions}
                    <Card class="border-2">
                        <CardHeader>
                            <CardTitle class="flex items-center space-x-2">
                                <ClipboardList class="h-5 w-5" />
                                <span>Instructions</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="text-sm p-3 bg-blue-50 border border-blue-200 rounded">
                                {data.instructions}
                            </div>
                        </CardContent>
                    </Card>
                {/if}

                <!-- Doctor Information -->
                {#if data.doctor}
                    <Card class="border-2">
                        <CardHeader>
                            <CardTitle class="flex items-center space-x-2">
                                <UserCheck class="h-5 w-5" />
                                <span>Doctor Information</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                <div>
                                    <div class="text-sm font-medium text-muted-foreground">Name</div>
                                    <div class="text-sm">{data.doctor.name}</div>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-muted-foreground">Signature</div>
                                    <div class="text-sm font-mono bg-muted p-2 rounded">{data.doctor.signature}</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                {/if}
            {:else if transcript.status === 'pending'}
                <Card class="border-2">
                    <CardContent class="py-16 text-center">
                        <Clock class="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                        <h3 class="text-lg font-semibold mb-2">Transcription Queued</h3>
                        <p class="text-muted-foreground">Your document is in the queue for processing. This usually takes 30-60 seconds.</p>
                    </CardContent>
                </Card>
            {:else if transcript.status === 'processing'}
                <Card class="border-2">
                    <CardContent class="py-16 text-center">
                        <Loader class="h-12 w-12 text-blue-500 mx-auto mb-4 animate-spin" />
                        <h3 class="text-lg font-semibold mb-2">Processing Document</h3>
                        <p class="text-muted-foreground">Our AI is analyzing your handwritten document. This may take a few minutes.</p>
                        <div class="mt-4 text-xs text-muted-foreground">This page will update automatically when processing is complete.</div>
                    </CardContent>
                </Card>
            {:else if transcript.status === 'failed'}
                <Card class="border-2 border-red-200">
                    <CardContent class="py-16 text-center">
                        <XCircle class="h-12 w-12 text-red-500 mx-auto mb-4" />
                        <h3 class="text-lg font-semibold mb-2">Transcription Failed</h3>
                        <p class="text-muted-foreground mb-6">
                            There was an error processing your document. You can try again or edit the transcript to upload a different image.
                        </p>
                        <div class="flex justify-center space-x-4">
                            <Button onclick={retryTranscription} class="border-2">
                                <RefreshCw class="inline" />
                                Retry Processing
                            </Button>
                            <Link href={`/transcripts/${transcript.id}/edit`}>
                                <Button variant="outline" class="border-2">
                                    <Edit class="inline" />
                                    Edit Document
                                </Button>
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            {/if}
        </div>
    </div>
</AppLayout>
