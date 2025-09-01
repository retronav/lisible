<script lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Card, CardContent } from '@/components/ui/card';
    import { Badge } from '@/components/ui/badge';
    import { type BreadcrumbItem, type PaginatedTranscripts, type TranscriptStatus } from '@/types';
    import { Link, router } from '@inertiajs/svelte';
    import { FileText, Search, Filter, Clock, CheckCircle, XCircle, Loader, Calendar, Eye, Upload } from 'lucide-svelte';
    import { onMount } from 'svelte';
    import * as Select from '@/components/ui/select';

    interface Props {
        transcripts: PaginatedTranscripts;
        search?: string;
        status?: TranscriptStatus;
    }

    let { transcripts, search = '', status }: Props = $props();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Transcripts',
            href: '/transcripts',
        },
    ];

    let searchQuery = $state(search);
    let selectedStatus: TranscriptStatus | '' = $state(status || '');
    let searchTimeout: ReturnType<typeof setTimeout>;

    const statusConfig = {
        pending: {
            label: 'Pending',
            icon: Clock,
            class: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        },
        processing: {
            label: 'Processing',
            icon: Loader,
            class: 'bg-blue-100 text-blue-800 border-blue-200',
        },
        completed: {
            label: 'Completed',
            icon: CheckCircle,
            class: 'bg-green-100 text-green-800 border-green-200',
        },
        failed: {
            label: 'Failed',
            icon: XCircle,
            class: 'bg-red-100 text-red-800 border-red-200',
        },
    };
    let selectSearchContent = $derived(selectedStatus ? statusConfig[selectedStatus].label : 'Select Status');

    const performSearch = () => {
        const params = new URLSearchParams();
        if (searchQuery.trim()) {
            params.set('search', searchQuery.trim());
        }
        if (selectedStatus) {
            params.set('status', selectedStatus);
        }

        const url = params.toString() ? `/transcripts?${params.toString()}` : '/transcripts';
        router.get(url, {}, { preserveState: true, replace: true });
    };

    const handleSearchInput = () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 300);
    };

    const handleStatusFilter = () => {
        performSearch();
    };

    const clearFilters = () => {
        searchQuery = '';
        selectedStatus = '';
        router.get('/transcripts', {}, { preserveState: true, replace: true });
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

    const getStatusClass = (status: TranscriptStatus) => {
        return statusConfig[status]?.class || 'bg-gray-100 text-gray-800';
    };

    onMount(() => {
        return () => {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
        };
    });
</script>

<svelte:head>
    <title>Transcripts</title>
</svelte:head>

<AppLayout {breadcrumbs}>
    <section class="m-4">
        <!-- Header Section -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary/10">
                    <FileText class="h-6 w-6 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Transcripts</h1>
                    <p class="text-muted-foreground">
                        {transcripts.total} total transcripts
                    </p>
                </div>
            </div>
            <Link href="/transcripts/create">
                <Button>
                    <Upload class="inline" />
                    Upload
                </Button>
            </Link>
        </div>

        <!-- Filters Section -->
        <Card class="mb-6">
            <CardContent class="p-4">
                <div class="flex flex-col sm:flex-row gap-4">
                    <!-- Search Input -->
                    <div class="flex-1 relative">
                        <Search class="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            bind:value={searchQuery}
                            oninput={handleSearchInput}
                            placeholder="Search transcripts by title or description..."
                            class="pl-10 border-2"
                        />
                    </div>

                    <!-- Status Filter -->
                    <div class="sm:w-48">
                        <Select.Root type="single" bind:value={selectedStatus} onValueChange={handleStatusFilter}>
                            <Select.Trigger class="w-full border-2">
                                {selectSearchContent}
                            </Select.Trigger>
                            <Select.Content>
                                <Select.Item value="">All Status</Select.Item>
                                <Select.Item value="pending">Pending</Select.Item>
                                <Select.Item value="processing">Processing</Select.Item>
                                <Select.Item value="completed">Completed</Select.Item>
                                <Select.Item value="failed">Failed</Select.Item>
                            </Select.Content>
                        </Select.Root>
                    </div>

                    <!-- Clear Filters -->
                    {#if searchQuery || selectedStatus}
                        <Button variant="outline" onclick={clearFilters} class="border-2">
                            <Filter class="inline" />
                            Clear
                        </Button>
                    {/if}
                </div>
            </CardContent>
        </Card>

        <!-- Transcripts List -->
        {#if transcripts.data.length === 0}
            <Card>
                <CardContent class="py-16 text-center">
                    <FileText class="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                    <h3 class="text-lg font-semibold mb-2">No transcripts found</h3>
                    <p class="text-muted-foreground mb-6">
                        {#if searchQuery || selectedStatus}
                            Try adjusting your search filters or create a new transcript.
                        {:else}
                            Get started by creating your first transcript.
                        {/if}
                    </p>
                    <Link href="/transcripts/create">
                        <Button>
                            <Upload class="inline" />
                            Upload Transcript
                        </Button>
                    </Link>
                </CardContent>
            </Card>
        {:else}
            <div class="space-y-4">
                {#each transcripts.data as transcript (transcript.id)}
                    <Card class="hover:shadow-md transition-shadow border-2">
                        <CardContent class="p-6">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-semibold text-foreground truncate">
                                            <Link href={`/transcripts/${transcript.id}`} class="hover:text-primary transition-colors">
                                                {transcript.title}
                                            </Link>
                                        </h3>
                                        <Badge class={`border ${getStatusClass(transcript.status)}`}>
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
                                    </div>

                                    {#if transcript.description}
                                        <p class="text-muted-foreground text-sm mb-3 line-clamp-2">
                                            {transcript.description}
                                        </p>
                                    {/if}

                                    <div class="flex items-center space-x-4 text-sm text-muted-foreground">
                                        <div class="flex items-center space-x-1">
                                            <Calendar class="h-4 w-4" />
                                            <span>Created {formatDate(transcript.created_at)}</span>
                                        </div>
                                        {#if transcript.processed_at}
                                            <div class="flex items-center space-x-1">
                                                <CheckCircle class="h-4 w-4" />
                                                <span>Processed {formatDate(transcript.processed_at)}</span>
                                            </div>
                                        {/if}
                                    </div>

                                    {#if transcript.error_message}
                                        <div class="mt-2 p-2 bg-red-50 border border-red-200 rounded text-sm text-red-800">
                                            {transcript.error_message}
                                        </div>
                                    {/if}
                                </div>

                                <div class="ml-4 flex-shrink-0">
                                    <Button variant="outline" size="sm" class="border-2">
                                        <Link href={`/transcripts/${transcript.id}`}>
                                            <Eye class="inline" />
                                            View
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                {/each}
            </div>

            <!-- Pagination -->
            {#if transcripts.last_page > 1}
                <div class="mt-8 flex justify-center">
                    <nav class="flex items-center space-x-1">
                        {#each transcripts.links as link (link.label)}
                            {#if link.url}
                                <Link href={link.url}>
                                    <Button variant={link.active ? 'default' : 'outline'} size="sm" class="border-2">
                                        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                                        {@html link.label}
                                    </Button>
                                </Link>
                            {:else}
                                <Button variant="outline" size="sm" disabled class="border-2">
                                    <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                                    {@html link.label}
                                </Button>
                            {/if}
                        {/each}
                    </nav>
                </div>
            {/if}
        {/if}
    </section>
</AppLayout>
