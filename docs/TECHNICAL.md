# Introduction

__Lisible__ is a web application which transcribes physical handwritten doctor's
notes and prescription into readable, structured text using multimodal LLMs.

This document covers the technical, functional and operational requirements of
the application.

# Technical requirements

The web application will use Laravel serving as the overall framework for
managing everything. The user-accessible content will use Svelte. The database
for choice is SQLite because of prioritizing local use but it can be switched to
something else later. The tests will be written in Pest.

# Overall architecture

## Application architecture

**Lisible** is built as a modern full-stack web application using Laravel 12 as
the backend API and Svelte 5 with Inertia.js for the frontend. The application
follows a clean architecture pattern with clear separation of concerns between
the presentation layer, business logic, and data persistence.

### Technology Stack

**Backend Framework:**
- **Laravel 12.0** - Primary PHP framework providing robust MVC architecture,
  eloquent ORM, queue system, and authentication
- **PHP 8.2+** - Modern PHP version with improved performance and type safety
- **SQLite** - Lightweight embedded database for local development and
  deployment simplicity
- **Laravel Queue** - Built-in job queue system using database driver for
  background processing

**Frontend Framework:**
- **Svelte 5** - Modern reactive JavaScript framework with excellent performance
  and developer experience
- **Inertia.js 2.0** - Modern monolith approach connecting Laravel backend with
  Svelte frontend without API overhead
- **TypeScript** - Type-safe JavaScript with enhanced developer tooling
- **Vite 6** - Fast build tool and development server with hot module
  replacement
- **Server-Side Rendering (SSR)** - Enabled for better SEO and initial page load
  performance

**UI/UX Libraries:**
- **TailwindCSS 4.0** - Utility-first CSS framework for consistent styling
- **Bits UI** - Accessible Svelte component library for form controls and
  interactions
- **Lucide Svelte** - Comprehensive icon library with Svelte bindings
- **Embla Carousel** - Touch-friendly carousel/slider component
- **Svelte Sonner** - Toast notification system
- **Vaul Svelte** - Mobile-friendly drawer/modal components

**Development & Quality Tools:**
- **Pest PHP** - Modern PHP testing framework for feature and unit tests
- **ESLint & Prettier** - Code linting and formatting for consistent code style
- **Laravel Pint** - PHP code style fixer based on PHP-CS-Fixer
- **Ziggy.js** - Laravel route generation for frontend navigation

### Architecture Patterns

**Backend Architecture:**
- **MVC Pattern** - Controllers handle HTTP requests, Models manage data, Views
  rendered via Inertia
- **Repository Pattern** - Models serve as repositories with Eloquent ORM
  abstraction
- **Job-Queue Pattern** - Background processing for CPU-intensive transcription
  tasks
- **Service Provider Pattern** - Dependency injection and service binding via
  Laravel's container
- **Middleware Pattern** - Request filtering for authentication, validation, and
  cross-cutting concerns

**Frontend Architecture:**
- **Component-Based Architecture** - Modular Svelte components with clear data
  flow
- **Layout System** - Hierarchical layout components for consistent page
  structure
- **State Management** - Reactive Svelte stores and Inertia shared state
- **Type Safety** - TypeScript throughout the frontend with defined interfaces
- **Utility-First Styling** - TailwindCSS for rapid UI development without
  custom CSS

### Application Structure

**Directory Organization:**
```
├── app/                          # Laravel application logic
│   ├── Http/Controllers/         # HTTP request handling
│   │   ├── Auth/                 # Authentication controllers
│   │   └── Settings/             # User settings management
│   ├── Models/                   # Eloquent models and business logic
│   ├── Jobs/                     # Background job classes (planned)
│   ├── Notifications/            # Email/notification classes
│   └── Providers/                # Service providers for DI
├── resources/js/                 # Frontend Svelte application
│   ├── components/               # Reusable UI components
│   ├── layouts/                  # Page layout components
│   ├── pages/                    # Page components (Inertia routes)
│   ├── lib/                      # Utility functions and helpers
│   ├── hooks/                    # Custom Svelte actions/hooks
│   └── types/                    # TypeScript type definitions
├── routes/                       # Application routing
│   ├── web.php                   # Main web routes
│   ├── auth.php                  # Authentication routes
│   └── settings.php              # Settings management routes
├── database/                     # Database layer
│   ├── migrations/               # Schema migrations
│   ├── factories/                # Model factories for testing
│   └── seeders/                  # Database seeding
└── tests/                        # Test suites
    ├── Feature/                  # Integration/feature tests
    └── Unit/                     # Unit tests
```

## Transcript Model

A Transcript model contains the required information to store, display and
transcribe handwritten transcripts.

```
Transcript
    id                          int
    title                       text
    description                 text?
    image                       blob
    transcript                  text?  // JSON - nullable until processing complete
    status                      enum   // pending, processing, completed, failed
    error_message               text?  // error details if status is failed
    processed_at                timestamp?
    created_at                  timestamp
    updated_at                  timestamp
```

Property-specific notes:

- `transcript`: JSON schema will be this:
```yaml
type: object
properties:
    patient:
        type: object
        properties:
            name:
                type: string
            age:
                type: integer
            gender:
                type: string
        required:
            - name
            - age
            - gender
    date:
        type: string
        format: date
    prescriptions:
        type: array
        items:
            type: object
            properties:
                drug_name:
                    type: string
                dosage:
                    type: string
                route:
                    type: string
                frequency:
                    type: string
                duration:
                    type: string
                notes:
                    type: string
                    nullable: true
            required:
                - drug_name
                - dosage
                - route
                - frequency
                - duration
    diagnoses:
        type: array
        items:
            type: object
            properties:
                condition:
                    type: string
                notes:
                    type: string
                    nullable: true
            required:
                - condition
    observations:
        type: array
        items:
            type: string
    tests:
        type: array
        items:
            type: object
            properties:
                test_name:
                    type: string
                result:
                    type: string
                    nullable: true
                normal_range:
                    type: string
                    nullable: true
                notes:
                    type: string
                    nullable: true
            required:
                - test_name
    instructions:
        type: string
    doctor:
        type: object
        properties:
            name:
                type: string
            signature:
                type: string
        required:
            - name
            - signature
required:
    - patient
    - date
    - prescriptions
    - diagnoses
    - observations
    - tests
    - instructions
    - doctor
```

## Controller Methods

### TranscriptController

The TranscriptController handles all CRUD operations for transcripts and manages
the transcription workflow.

**GET /transcripts** - `index()`
- Lists all transcripts with pagination
- Returns view with transcript summaries (id, title, status, created_at)
- Supports search and filtering by status

**GET /transcripts/create** - `create()`
- Shows form for creating new transcript
- Returns view with upload interface for handwritten images

**POST /transcripts** - `store(Request $request)`
- Validates uploaded image and form data
- Creates new Transcript record with status 'pending'
- Dispatches transcription job to queue
- Returns JSON response with transcript ID and status for AJAX polling

**GET /transcripts/{transcript}** - `show(Transcript $transcript)`
- Displays individual transcript details
- Shows original image and transcription status
- If completed, shows transcribed content with structured display
- If processing, shows progress indicator
- If failed, shows error message with retry option

**GET /transcripts/{transcript}/status** - `status(Transcript $transcript)`
- API endpoint returning current processing status as JSON
- Used for AJAX polling to update UI without page refresh
- Returns: `{status, progress, error_message, transcript_data}`

**POST /transcripts/{transcript}/retry** - `retry(Transcript $transcript)`
- Resets failed transcript status to 'pending'
- Dispatches new transcription job
- Only available for transcripts with 'failed' status

**GET /transcripts/{transcript}/edit** - `edit(Transcript $transcript)`
- Shows form for editing transcript metadata
- Allows re-uploading image to trigger re-transcription
- Disabled while status is 'processing'

**PUT /transcripts/{transcript}** - `update(Request $request, Transcript $transcript)`
- Updates transcript title and description
- If new image uploaded and status is not 'processing':
  - Resets status to 'pending'
  - Clears existing transcript data
  - Dispatches new transcription job
- Redirects back to transcript show page

**DELETE /transcripts/{transcript}** - `destroy(Transcript $transcript)`
- Soft deletes transcript record
- Cancels any pending jobs for this transcript
- Cleans up associated image files
- Redirects to transcripts index

# User Flows

## Creating a New Transcript

1. User navigates to `/transcripts/create`
2. User fills in title and optional description
3. User uploads handwritten document image
4. User submits form
5. System validates input and creates transcript record
6. System triggers transcription process (see Transcription Process section)
7. User is redirected to transcript detail page
8. User can view transcription results once processing completes

## Viewing Transcripts

1. User visits `/transcripts` to see list of all transcripts
2. User can search/filter transcripts by title or date
3. User clicks on specific transcript to view details
4. User sees original image alongside transcribed text
5. User can copy transcribed text or download as file

## Updating a Transcript

1. User navigates to transcript detail page
2. User clicks "Edit" button
3. User can modify title and description
4. User can optionally upload new image to re-transcribe
5. User submits changes
6. System updates record and re-processes if new image provided
7. User is redirected back to updated transcript

## Deleting a Transcript

1. User navigates to transcript detail page
2. User clicks "Delete" button
3. System shows confirmation dialog
4. User confirms deletion
5. System soft deletes transcript and cleans up files
6. User is redirected to transcripts list

# Transcription Process

## Gemini API Integration

The transcription process utilizes Google's Gemini API for multimodal analysis
of handwritten documents through a job-based queue system.

The model of choice is `gemini-2.5-flash` with thinking mode and structured
output

### Job Processing Workflow

1. **Job Creation**: When a transcript is created/updated with new image, a
   `ProcessTranscription` job is dispatched
2. **Queue Processing**: Laravel queue worker picks up jobs and processes them
   sequentially
3. **Status Updates**: Job updates transcript status throughout processing
   lifecycle
4. **Error Handling**: Failed jobs update transcript with error details and can
   be retried

### Job Status Flow

- `pending`: Transcript created, job queued but not yet started
- `processing`: Job picked up by worker, API call in progress
- `completed`: Transcription successful, structured data saved
- `failed`: Processing failed, error message stored for user review

## Technical Details

- **Laravel Queue**: Utilizes Laravel's built-in queue system for managing
  background jobs.
- **Gemini API Client**: Interacts with Google's Gemini API for transcription
  tasks.
- **Job Class**: `ProcessTranscription` job class handles the transcription
  logic and status updates.
- **Error Handling**: Captures and logs errors during API interaction, updates
  transcript status to 'failed' with error details.

## ProcessTranscription Job Schema

The `ProcessTranscription` job is responsible for handling the asynchronous
transcription of handwritten documents using the Gemini API.

### Job Class Structure
- Class: ProcessTranscription
- Implements: ShouldQueue
- Uses traits: Dispatchable, InteractsWithQueue, Queueable, SerializesModels

- Properties:
    - timeout: 300 (seconds)
    - tries: 3 (max retries)
    - backoff: [30, 60, 120] (seconds between retries)
    - transcript: Transcript (protected)

- Constructor:
    - Accepts a Transcript instance and stores it on the class

- Methods:
    - handle(geminiClient): Main processing logic for transcription
        - Validates and prepares image
        - Calls Gemini with structured schema
        - Parses and validates response
        - Updates transcript data, status, and processed_at
        - Logs progress and metrics
    - failed(exception): Failure handler
        - Logs error details
        - Updates transcript status to failed
        - Stores user-friendly error message

### Job Processing Flow

1. **Initialization**
   - Load transcript model
   - Update status to 'processing'
   - Log processing start

2. **Image Preparation**
   - Validate image exists and is readable
   - Optimize image quality to ~80% if needed
   - Convert to base64 for API transmission

3. **API Request**
   - Configure Gemini client with structured output schema
   - Send multimodal request with image and system prompt
   - Handle API response and parse JSON structure

4. **Result Processing**
   - Validate response against expected schema
   - Save structured transcript data
   - Update status to 'completed'
   - Set processed_at timestamp

5. **Error Handling**
   - Catch API errors, validation errors, and exceptions
   - Log detailed error information
   - Update transcript status to 'failed'
   - Store user-friendly error message

### Queue Configuration

```php
// config/queue.php additions
'connections' => [
    'database' => [
        // ...existing config...
        'table' => 'jobs',
        'queue' => 'transcription',
        'retry_after' => 300,
    ],
],

// Queue priority: transcription jobs use dedicated queue
'transcription' => [
    'driver' => 'database',
    'table' => 'jobs',
    'queue' => 'transcription',
    'retry_after' => 300,
    'block_for' => 0,
],
```

### Error Categories

- **API_ERROR**: Gemini API returned error response
- **NETWORK_ERROR**: Network connectivity issues
- **VALIDATION_ERROR**: Response doesn't match expected schema
- **IMAGE_ERROR**: Image file corrupted or unreadable
- **TIMEOUT_ERROR**: Processing exceeded timeout limit

### Monitoring and Logging

- **Job Progress**: Updates transcript status at each processing stage
- **Error Logging**: Detailed logs for debugging failed transcriptions
- **Metrics Tracking**: Processing time, success rate, error frequency
- **Queue Health**: Monitor queue depth and processing delays

# Design Rationale

## Visual Design Philosophy

**Lisible** embraces a neo-brutalist design aesthetic that prioritizes clarity,
accessibility, and visual impact. This design approach aligns perfectly with the
application's core mission of transforming illegible handwritten medical
documents into clear, structured digital text.

### Core Design Principles

**Neo-Brutalist Foundation:**
- **Bold Typography**: Plus Jakarta Sans serves as the primary typeface, chosen
  for its excellent readability and modern character
- **Generous Spacing**: Liberal use of whitespace ensures content breathability
  and reduces cognitive load
- **Thick Borders**: Prominent 2-4px borders create clear visual separation and
  enhance usability
- **Rounded Corners**: 8-16px border radius softens the brutalist edges while
  maintaining structural clarity

**Color Philosophy - '84 Synthwave Palette:**
- **Base Colors**: Clean white backgrounds for all interactive elements ensure
  maximum contrast and readability
- **Success State**: Green (#22c55e) for completed transcriptions and positive
  actions
- **Danger State**: Red (#ef4444) for errors, deletions, and critical warnings
- **Accent Colors**:
  - Baby Pink (#f8bbd9) for secondary actions and highlights
  - Neon Orange (#ff7849) for processing states and attention-grabbing elements
  - Electric Yellow (#fbbf24) for warnings and pending states

### Accessibility Considerations

**Visual Accessibility:**
- High contrast ratios (minimum 4.5:1) between text and backgrounds
- Clear visual hierarchy through typography scale and spacing
- Color-blind friendly palette with shape and text alternatives to color coding
- Large touch targets (minimum 44px) for mobile interaction

**Interaction Design:**
- Consistent hover and focus states with clear visual feedback
- Loading states with progress indicators and descriptive text
- Error states with clear messaging and recovery options
- Keyboard navigation support throughout the application

### Component Design System

**Interactive Elements:**
- **Buttons**: White backgrounds with colored borders, transforming to filled states on hover
- **Form Inputs**: Clean white backgrounds with thick borders, focus states in accent colors
- **Cards**: Elevated white containers with subtle shadows and rounded corners
- **Status Indicators**: Color-coded with accompanying icons and text labels

**Information Architecture:**
- Clear visual hierarchy prioritizing medical data accuracy
- Scannable layouts optimized for quick content review
- Consistent spacing system (4px, 8px, 16px, 24px, 32px, 48px)
- Responsive breakpoints ensuring usability across devices

### Medical Context Considerations

**Professional Trust:**
- Clean, clinical aesthetic appropriate for healthcare environments
- Consistent branding and typography establishing reliability
- Clear data presentation reducing interpretation errors

**Workflow Optimization:**
- Minimal cognitive load through clear visual patterns
- Quick visual status recognition through consistent color coding
- Efficient information density without overwhelming complexity

# Miscallenous Notes

- Run `npm run build` to generate Vite's manifest.json which is required to run
  feature tests.

# Implementation Sprints

## Sprint 1: Foundation & Database Layer
**Objective**: Establish core data model and database infrastructure

**Tasks**:
- Create Transcript model with all required properties (id, title, description,
  image, transcript, status, error_message, processed_at, timestamps)
- Generate and configure database migration with proper column types and indexes
- Implement Transcript model with appropriate relationships, accessors, and
  mutators for JSON handling
- Create database seeders for development and testing data
- Write comprehensive unit tests for model validation, JSON schema compliance,
  and database operations
- Ensure all tests pass before proceeding to next sprint

**Deliverables**: Working Transcript model with full test coverage, database
schema ready for use

## Sprint 2: Job Queue Infrastructure
**Objective**: Build the asynchronous processing foundation for transcription

**Tasks**:
- Implement ProcessTranscription job class with proper queue interface
  implementation
- Configure Laravel queue system with dedicated 'transcription' queue and
  appropriate timeouts
- Create job failure handling with retry logic and exponential backoff
- Implement status tracking throughout job lifecycle (pending -> processing ->
  completed/failed)
- Write unit tests for job processing, error handling, and status updates
- Set up queue monitoring and logging infrastructure

**Deliverables**: Fully functional background job system for transcript
processing

## Sprint 3: Core Controller & API Endpoints
**Objective**: Build the main application logic and API layer

**Tasks**:
- Implement TranscriptController with all CRUD operations (index, create, store,
  show, edit, update, destroy)
- Create status checking endpoint for AJAX polling during processing
- Implement retry mechanism for failed transcriptions
- Add proper request validation and error handling for all endpoints
- Create API routes with appropriate middleware (authentication, rate limiting)
- Write controller tests covering all endpoints and edge cases

**Deliverables**: Complete API layer with full CRUD functionality and status
management

## Sprint 4: User Authentication & Base Layout
**Objective**: Establish user system and application shell

**Tasks**:
- Verify and configure Laravel starter kit authentication system
- Customize authentication views and flows if needed
- Create base application layout with navigation, header, and footer components
- Implement user dashboard shell with placeholder sections for transcripts
- Add user settings page for profile management (name, password, email updates)
- Ensure proper middleware protection for authenticated routes

**Deliverables**: Working authentication system with user dashboard foundation

## Sprint 5: Transcript Creation Interface
**Objective**: Build the primary user entry point for new transcripts

**Tasks**:
- Design and implement transcript creation form with image upload capability
- Add client-side validation for file types, sizes, and required fields
- Implement drag-and-drop file upload with preview functionality
- Create progress indicators and status feedback for job submission
- Add AJAX-based status polling to show real-time processing updates
- Implement error handling and user-friendly error messages
- Write integration tests for the complete creation workflow

**Deliverables**: Fully functional transcript creation interface with real-time
status updates

## Sprint 6: Transcript Management Dashboard
**Objective**: Build comprehensive transcript listing and management interface

**Tasks**:
- Implement transcript listing page with status-aware display (pending,
  processing, completed, failed)
- Add search functionality across title and description fields
- Create filtering options by status, date range, and other relevant criteria
- Implement pagination with configurable page sizes
- Design status indicators with appropriate visual cues and colors
- Add bulk operations (delete multiple, retry failed transcriptions)
- Create responsive design for mobile and desktop viewing

**Deliverables**: Complete transcript management interface with search, filter,
and pagination

## Sprint 7: Transcript Detail & Update Interface
**Objective**: Provide detailed view and editing capabilities for individual
transcripts

**Tasks**:
- Build transcript detail page with structured display of transcribed content
- Implement side-by-side view of original image and structured output
- Create edit interface for metadata (title, description) and image re-upload
- Add copy-to-clipboard functionality for transcribed text
- Implement export options (PDF, plain text)
- Design retry interface for failed transcriptions with clear error messaging
- Add soft delete confirmation dialogs with appropriate warnings

**Deliverables**: Comprehensive transcript viewing and editing interface

## Sprint 8: Gemini API Integration
**Objective**: Connect the application to Google's Gemini API for actual
transcription

**Tasks**:
- Integrate Google Gemini PHP client library
- Configure API authentication and rate limiting
- Implement structured output schema matching the transcript JSON format
- Create robust error handling for API failures, timeouts, and quota exceeded
  scenarios
- Add image preprocessing and optimization pipeline
- Implement prompt engineering for medical transcription accuracy
- Create comprehensive logging for API interactions and performance monitoring

**Deliverables**: Working AI transcription with proper error handling and
monitoring

## Sprint 9: End-to-End Testing & Quality Assurance
**Objective**: Ensure application reliability through comprehensive testing

**Tasks**:
- Write end-to-end tests covering complete user workflows (create -> process ->
  view -> edit -> delete)
- Implement browser automation tests using Laravel Dusk for critical user paths
- Create performance tests for image upload, processing queues, and database
  operations
- Add accessibility testing and compliance verification
- Perform security testing including file upload validation and SQL injection
  prevention
- Conduct user acceptance testing with real medical document samples
- Create test data fixtures and automated test environment setup

**Deliverables**: Fully tested application with high confidence in reliability
and security

## Sprint 10: Production Readiness & Deployment
**Objective**: Prepare application for production deployment and monitoring

**Tasks**:
- Configure production environment variables and security settings
- Set up database backup and recovery procedures
- Implement application monitoring with error tracking and performance metrics
- Create deployment scripts and CI/CD pipeline configuration
- Add rate limiting and security headers for production use
- Configure queue workers for production with proper process management
- Create user documentation and administrator guides
- Perform final security audit and performance optimization

**Deliverables**: Production-ready application with monitoring, backup, and
deployment infrastructure
