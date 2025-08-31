import '@inertiajs/svelte';
import type { Config } from 'ziggy-js';


export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: any;
    isActive?: boolean;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
    ziggy: Config & { location: string };
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type TranscriptStatus = 'pending' | 'processing' | 'completed' | 'failed';

export interface Patient {
    name: string;
    age: number;
    gender: string;
}

export interface Prescription {
    drug_name: string;
    dosage: string;
    route: string;
    frequency: string;
    duration: string;
    notes?: string;
}

export interface Diagnosis {
    condition: string;
    notes?: string;
}

export interface Test {
    test_name: string;
    result?: string;
    normal_range?: string;
    notes?: string;
}

export interface Doctor {
    name: string;
    signature: string;
}

export interface TranscriptData {
    patient: Patient;
    date: string;
    prescriptions: Prescription[];
    diagnoses: Diagnosis[];
    observations: string[];
    tests: Test[];
    instructions: string;
    doctor: Doctor;
}

export interface Transcript {
    id: number;
    title: string;
    description?: string;
    image?: string;
    transcript?: TranscriptData;
    status: TranscriptStatus;
    error_message?: string;
    processed_at?: string;
    created_at: string;
    updated_at: string;
}

export interface PaginatedTranscripts {
    data: Transcript[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{
        url?: string;
        label: string;
        active: boolean;
    }>;
}

export interface TranscriptFormData {
    title: string;
    description?: string;
    image?: File;
}

export interface ValidationErrors {
    [key: string]: string[];
}

export type BreadcrumbItemType = BreadcrumbItem;
