# TSM Database Structure

This document outlines the database structure for the Task Management System (TSM) with enhanced team functionality.

## Tables Overview

The database consists of the following main tables:
- `users` - Stores user information with team assignments
- `teams` - Stores team information
- `tasks` - Stores all tasks in the system with team associations
- `subtasks` - Stores subtasks/checklist items for each task
- `messages` - Stores communication messages between users
- `team_messages` - Stores team-wide messages and announcements
- `notifications` - Stores system notifications for users
- `task_templates` - Stores reusable task templates
- `kudos` - Stores recognition/kudos between team members
- `delegations` - Stores task delegation records
- `task_time_logs` - Stores time tracking for tasks (optional)
- `team_admin_permissions` - Stores permission settings for team admins
- `activity_logs` - Stores system activity logs with team context

## Schema Details

### Teams Table

The teams table stores information about teams in the organization.

```sql
CREATE TABLE teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_by INT NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);
```

Fields:
- `id` - Unique identifier for each team
- `name` - Team name
- `created_by` - ID of the user who created the team (typically admin)
- `status` - Team status (active or inactive)
- `created_at` - Timestamp when team was created

### Users Table

The users table stores information about all system users with team associations.

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'team_admin', 'employee') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    team_id INT NULL,
    parent_admin_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_admin_id) REFERENCES users(id) ON DELETE SET NULL
);
```

Fields:
- `id` - Unique identifier for each user
- `full_name` - User's full name
- `email` - User's email address (used for login)
- `password` - Hashed password
- `role` - User role (admin, team_admin, or employee)
- `status` - User account status
- `team_id` - ID of the team the user belongs to (foreign key)
- `parent_admin_id` - ID of the admin who manages this user (for team_admin users)
- `created_at` - Timestamp when user was created

### Tasks Table

The tasks table stores all tasks in the system with team associations.

```sql
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    status ENUM('to_do', 'in_progress', 'completed') DEFAULT 'to_do',
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    deadline DATE,
    created_by INT,
    assigned_to INT,
    team_id INT NULL,
    archived BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
);
```

Fields:
- `id` - Unique identifier for each task
- `title` - Task title
- `description` - Detailed description of the task
- `status` - Current status of the task: to_do, in_progress, or completed
- `priority` - Task priority level
- `deadline` - Due date for the task
- `created_by` - ID of the user who created the task
- `assigned_to` - ID of the user the task is assigned to
- `team_id` - ID of the team this task belongs to
- `archived` - Whether the task is archived
- `created_at` - Timestamp when task was created

### Team Admin Permissions Table

The team_admin_permissions table stores permission settings for team administrators.

```sql
CREATE TABLE team_admin_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    team_id INT NOT NULL,
    can_assign BOOLEAN DEFAULT TRUE,
    can_edit BOOLEAN DEFAULT TRUE,
    can_archive BOOLEAN DEFAULT TRUE,
    can_add_members BOOLEAN DEFAULT TRUE,
    can_view_reports BOOLEAN DEFAULT TRUE,
    can_send_messages BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_team (user_id, team_id)
);
```

Fields:
- `id` - Unique identifier
- `user_id` - ID of the team admin user
- `team_id` - ID of the team
- `can_assign` - Permission to assign tasks
- `can_edit` - Permission to edit tasks
- `can_archive` - Permission to archive tasks
- `can_add_members` - Permission to add team members
- `can_view_reports` - Permission to view team reports
- `can_send_messages` - Permission to send team messages

### Notifications Table

The notifications table stores system notifications for users.

```sql
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('task_assigned', 'task_completed', 'task_overdue', 'message_received', 'team_update', 'kudos_received') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    related_id INT NULL,
    related_type ENUM('task', 'message', 'user', 'team') NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Task Templates Table

The task_templates table stores reusable task templates.

```sql
CREATE TABLE task_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    created_by INT NOT NULL,
    team_id INT NULL,
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    estimated_hours DECIMAL(5,2) NULL,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
);
```

### Kudos Table

The kudos table stores recognition and appreciation between team members.

```sql
CREATE TABLE kudos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    giver_id INT NOT NULL,
    receiver_id INT NOT NULL,
    task_id INT NULL,
    message TEXT NOT NULL,
    points INT DEFAULT 1,
    type ENUM('excellent_work', 'helpful', 'creative', 'leadership', 'teamwork', 'problem_solving') DEFAULT 'excellent_work',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (giver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
);
```

### Delegations Table

The delegations table stores task delegation records.

```sql
CREATE TABLE delegations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delegator_id INT NOT NULL,
    delegate_id INT NOT NULL,
    task_id INT NOT NULL,
    reason TEXT,
    status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    delegated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    FOREIGN KEY (delegator_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (delegate_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
```

### Team Messages Table

The team_messages table stores team-wide messages and announcements.

```sql
CREATE TABLE team_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    team_id INT NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    priority ENUM('normal', 'high', 'urgent') DEFAULT 'normal',
    is_announcement BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);
```

### Task Time Logs Table (Optional)

The task_time_logs table stores time tracking information for tasks.

```sql
CREATE TABLE task_time_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Subtasks Table

The subtasks table stores checklist items for each main task.

```sql
CREATE TABLE subtasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('to_do', 'done') DEFAULT 'to_do',
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
```

### Messages Table

The messages table stores direct communication between users.

```sql
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    task_id INT NULL,
    parent_message_id INT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_status ENUM('read', 'unread') DEFAULT 'unread',
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_message_id) REFERENCES messages(id) ON DELETE SET NULL
);
```

### Activity Logs Table

The activity_logs table stores system activity logs with team context.

```sql
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    details TEXT,
    old_status VARCHAR(50),
    new_status VARCHAR(50),
    team_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (task_id) REFERENCES tasks(id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
);
```

## Relationships

### Core Relationships
- A **team** has multiple users (one-to-many relationship from teams to users)
- A **user** belongs to one team (many-to-one relationship from users to teams)
- A **team admin** can manage one team and reports to an admin (parent_admin_id)
- A **task** belongs to one team (many-to-one relationship from tasks to teams)

### Task Relationships
- A **user** can create multiple tasks (one-to-many relationship from users to tasks via created_by)
- A **user** can be assigned multiple tasks (one-to-many relationship from users to tasks via assigned_to)
- A **task** can have multiple subtasks (one-to-many relationship from tasks to subtasks)
- A **task** can be created from a template (many-to-one relationship from tasks to task_templates)

### Communication Relationships
- A **user** can send multiple messages (one-to-many relationship from users to messages via sender_id)
- A **user** can receive multiple messages (one-to-many relationship from users to messages via recipient_id)
- A **team** can have multiple team messages (one-to-many relationship from teams to team_messages)
- A **user** can receive multiple notifications (one-to-many relationship from users to notifications)

### Recognition and Delegation
- A **user** can give multiple kudos (one-to-many relationship from users to kudos via giver_id)
- A **user** can receive multiple kudos (one-to-many relationship from users to kudos via receiver_id)
- A **user** can delegate multiple tasks (one-to-many relationship from users to delegations via delegator_id)
- A **user** can be delegated multiple tasks (one-to-many relationship from users to delegations via delegate_id)

## Installation and Setup

To implement this schema:

1. **Run the Schema Update Script:**
   ```bash
   mysql -u your_username -p your_database < database_schema_update.sql
   ```

2. **Verify Tables Creation:**
   ```sql
   SHOW TABLES;
   DESCRIBE users;
   DESCRIBE teams;
   ```

3. **Check Seed Data:**
   ```sql
   SELECT * FROM teams;
   SELECT * FROM users WHERE role = 'team_admin';
   SELECT * FROM team_admin_permissions;
   ```

## Database Diagram

```
  +-----------------+       +------------------+       +------------------+
  |     TEAMS       |       |      USERS       |       |      TASKS       |
  +-----------------+       +------------------+       +------------------+
  | id (PK)         |<---+  | id (PK)          |----+  | id (PK)          |
  | name            |    |  | full_name        |    |  | title            |
  | created_by (FK) |----+  | email            |    |  | description      |
  | status          |    |  | password         |    |  | status           |
  | created_at      |    |  | role             |    |  | priority         |
  +-----------------+    |  | status           |    |  | deadline         |
                         |  | team_id (FK)     |----+  | created_by (FK)  |<---+
                         |  | parent_admin_id  |    |  | assigned_to (FK) |<---+
                         |  | created_at       |    |  | team_id (FK)     |----+
                         |  +------------------+    |  | archived         |    |
                         |                          |  | created_at       |    |
                         |  +------------------+    |  +------------------+    |
                         |  | NOTIFICATIONS    |    |                          |
                         |  +------------------+    |  +------------------+    |
                         +->| id (PK)          |    |  |     SUBTASKS     |    |
                            | user_id (FK)     |<---+  +------------------+    |
                            | type             |       | id (PK)          |    |
                            | title            |       | task_id (FK)     |----+
                            | message          |       | title            |
                            | is_read          |       | status           |
                            | created_at       |       +------------------+
                            +------------------+
```

## Performance Indexes

The following indexes are created for optimal performance:
- `idx_users_team_id` on users(team_id)
- `idx_tasks_team_id` on tasks(team_id)
- `idx_notifications_user_id` on notifications(user_id)
- `idx_notifications_is_read` on notifications(is_read)
- `idx_task_time_logs_task_user` on task_time_logs(task_id, user_id)
- `idx_team_messages_team_id` on team_messages(team_id)
- `idx_activity_logs_team_id` on activity_logs(team_id)