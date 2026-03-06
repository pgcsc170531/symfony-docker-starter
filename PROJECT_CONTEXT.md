Project Context: Multi-Tenant School Finance SaaS
Target Market: Nigerian Primary/Secondary Schools (K-12). Core Architecture: Database-per-Tenant (Multi-Tenancy).

1. Tech Stack & Environment
OS: Windows 11 (WSL 2 - Ubuntu).

Server: Docker (FrankenPHP) + MySQL 8.0.

Backend: Symfony 7 + PHP 8.2+.

Frontend: Twig Templates + Tailwind CSS (CDN).

Database Access: Doctrine ORM.

2. Architecture Overview
The system is split into two distinct zones. Strict separation must be maintained.

A. The Landlord (Admin)
Domain: localhost:8080 (Main Domain).

Database: landlord_db.

Purpose: Manages the list of Schools (Tenants), subscriptions, and global settings.

Entity Location: src/Entity/Landlord (e.g., School).

Controller Location: src/Controller/Landlord.

B. The Tenant (School)
Domain: *.localhost:8080 (e.g., royal.localhost, apex.localhost).

Database: school_{subdomain} (e.g., school_royal).

Purpose: The actual school ERP (Students, Fees, Classes).

Entity Location: src/Entity/Tenant (e.g., Student, Classroom, Session, Term).

Controller Location: src/Controller/Tenant.

C. The "Magic" Switcher
Listener: src/EventListener/TenantListener.php.

Logic: Intercepts requests. If a subdomain exists, it finds the school in landlord_db, disconnects the default DB connection, and reconnects to the specific school_{subdomain} database.

3. Data Schema (Current State)
Landlord Entity
School: id, name, subdomain (unique), databaseName, isActive.

Tenant Entities
Session: id, name (e.g., "2024/2025"), isActive (bool).

One-to-Many -> Term.

Term: id, name (e.g., "First Term"), isActive, session_id.

Classroom: id, name (e.g., "JSS 1").

One-to-Many -> Student.

Student: id, name, classroom_id.

4. Critical Workflows (AI Instructions)
Rule #1: Managing Tenant Schemas
WE DO NOT USE DOCTRINE MIGRATIONS FOR TENANTS.

Why: Migrations are hard to manage across 100+ dynamic databases.

How we do it: We use Doctrine\ORM\Tools\SchemaTool.

The Command: Whenever a Tenant Entity is modified, run:

Bash

docker compose exec php bin/console app:tenant:update-schema
This command loops through all schools and updates their tables safely.

Rule #2: Landlord vs. Tenant Routing
Landlord Controllers must have: #[Route(..., host: 'localhost')]

Tenant Controllers must have: #[Route(..., host: '{subdomain}.localhost')]

Rule #3: Creating a New School
Do not manually create databases.

Use the Landlord Form (/landlord/school/new). The SchoolController handles:

Creating the Record.

Creating the Database.

Running SchemaTool to build tables.

5. Roadmap (Next Steps)
We are building the Finance Module. The next features to implement, in order:

Fee Heads (Categories): Create FeeHead entity (e.g., "Tuition", "Uniform", "Bus").

Fee Structure: Create FeeStructure entity linking Classroom + Term + FeeHead + Amount. (e.g., "JSS 1 students pay ₦50,000 for Tuition in First Term").

Invoicing: Auto-generate Invoice records for Students based on the active Session/Term.

Payments: Record partial or full payments against Invoices.

6. Important Commands
Start Server: docker compose up -d

Enter PHP Container: docker compose exec php /bin/bash

Update Tenant DBs: docker compose exec php bin/console app:tenant:update-schema

Clear Cache: docker compose exec php bin/console cache:clear