<script lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
    import { Badge } from '@/components/ui/badge';
    import {
        type BreadcrumbItem,
        type Transcript,
        type TranscriptStatus
    } from '@/types';
    import {
        FileText,
        Clock,
        CheckCircle,
        XCircle,
        Plus,
        Activity,
        TrendingUp,
        Calendar
    } from 'lucide-svelte';
    import { onMount } from 'svelte';

    interface Props {
        recentTranscripts: Transcript[];
        stats: {
            total: number;
            completed: number;
            processing: number;
            failed: number;
        };
    }

    let { recentTranscripts, stats }: Props = $props();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Dashboard',
            href: '/dashboard',
        },
    ];

    // Auto-refresh processing transcripts every 5 seconds
    let processingCount = $state(stats.processing);

    onMount(() => {
        const interval = setInterval(async () => {
            if (processingCount > 0) {
                try {
                    const response = await fetch('/api/transcripts/processing-count');
                    if (response.ok) {
                        const data = await response.json();
                        processingCount = data.count;
                    }
                } catch (error) {
                    console.error('Failed to refresh processing count:', error);
                }
            }
        }, 5000);

        return () => clearInterval(interval);
    });

    const getStatusColor = (status: TranscriptStatus): string => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800 border-green-200';
            case 'processing':
                return 'bg-blue-100 text-blue-800 border-blue-200';
            case 'failed':
                return 'bg-red-100 text-red-800 border-red-200';
            case 'pending':
            default:
                return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        }
    };

    const getStatusIcon = (status: TranscriptStatus) => {
        switch (status) {
            case 'completed':
                return CheckCircle;
            case 'processing':
                return Clock;
            case 'failed':
                return XCircle;
            case 'pending':
            default:
                return FileText;
        }
    };

    const formatDate = (dateString: string): string => {
        return new Date(dateString).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };
</script>

<svelte:head>
    <title>Dashboard</title>
</svelte:head>

<AppLayout {breadcrumbs}>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight">Dashboard</h1>
                <p class="text-muted-foreground">Overview of your transcription activity</p>
            </div>
            <Button href="/transcripts/create" class="flex items-center space-x-2">
                <Plus class="h-4 w-4" />
                <span>Create Transcript</span>
            </Button>
        </div>

        <!-- Stats Grid -->
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <!-- Total Transcripts -->
            <Card class="border-2">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Total Transcripts</CardTitle>
                    <FileText class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{stats.total}</div>
                    <p class="text-xs text-muted-foreground">
                        All time transcripts
                    </p>
                </CardContent>
            </Card>

            <!-- Completed -->
            <Card class="border-2">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Completed</CardTitle>
                    <CheckCircle class="h-4 w-4 text-green-600" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-green-600">{stats.completed}</div>
                    <p class="text-xs text-muted-foreground">
                        Successfully processed
                    </p>
                </CardContent>
            </Card>

            <!-- Processing -->
            <Card class="border-2">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Processing</CardTitle>
                    <Activity class="h-4 w-4 text-blue-600" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-blue-600">
                        {processingCount}
                        {#if processingCount > 0}
                            <div class="inline-block ml-2">
                                <div class="h-2 w-2 bg-blue-600 rounded-full animate-pulse"></div>
                            </div>
                        {/if}
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Currently processing
                    </p>
                </CardContent>
            </Card>

            <!-- Failed -->
            <Card class="border-2">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-sm font-medium">Failed</CardTitle>
                    <XCircle class="h-4 w-4 text-red-600" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold text-red-600">{stats.failed}</div>
                    <p class="text-xs text-muted-foreground">
                        Processing errors
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Recent Activity -->
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Recent Transcripts -->
            <Card class="border-2">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center space-x-2">
                                <Calendar class="h-5 w-5" />
                                <span>Recent Transcripts</span>
                            </CardTitle>
                            <CardDescription>
                                Your latest transcription activity
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" href="/transcripts">
                            View All
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    {#if recentTranscripts.length > 0}
                        <div class="space-y-4">
                            {#each recentTranscripts as transcript}
                                {@const StatusIcon = getStatusIcon(transcript.status)}
                                <div class="flex items-center space-x-4 p-3 rounded-lg border hover:bg-accent/50 transition-colors">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                        <StatusIcon class="h-5 w-5 text-primary" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <h3 class="text-sm font-medium truncate">
                                                {transcript.title}
                                            </h3>
                                            <Badge
                                                class="{getStatusColor(transcript.status)} text-xs border"
                                                variant="outline"
                                            >
                                                {transcript.status}
                                            </Badge>
                                        </div>
                                        <p class="text-xs text-muted-foreground">
                                            {formatDate(transcript.created_at)}
                                        </p>
                                    </div>
                                    <Button variant="ghost" size="sm" href="/transcripts/{transcript.id}">
                                        View
                                    </Button>
                                </div>
                            {/each}
                        </div>
                    {:else}
                        <div class="text-center py-8">
                            <FileText class="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                            <h3 class="text-lg font-medium">No transcripts yet</h3>
                            <p class="text-muted-foreground mb-4">
                                Get started by creating your first transcript
                            </p>
                            <Button href="/transcripts/create">
                                Create Transcript
                            </Button>
                        </div>
                    {/if}
                </CardContent>
            </Card>

            <!-- Quick Actions & Tips -->
            <Card class="border-2">
                <CardHeader>
                    <CardTitle class="flex items-center space-x-2">
                        <TrendingUp class="h-5 w-5" />
                        <span>Quick Actions</span>
                    </CardTitle>
                    <CardDescription>
                        Common tasks and helpful tips
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Quick Actions -->
                    <div class="space-y-3">
                        <Button
                            href="/transcripts/create"
                            class="w-full justify-start"
                            variant="outline"
                        >
                            <Plus class="h-4 w-4 mr-2" />
                            Upload New Document
                        </Button>
                        <Button
                            href="/transcripts"
                            class="w-full justify-start"
                            variant="outline"
                        >
                            <FileText class="h-4 w-4 mr-2" />
                            View All Transcripts
                        </Button>
                        {#if stats.processing > 0}
                            <Button
                                href="/transcripts?status=processing"
                                class="w-full justify-start"
                                variant="outline"
                            >
                                <Activity class="h-4 w-4 mr-2" />
                                Check Processing Status
                            </Button>
                        {/if}
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</AppLayout>
