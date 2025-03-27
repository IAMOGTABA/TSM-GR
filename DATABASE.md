# TSM Database Structure

This document outlines the database structure for the Task Management System (TSM).

## Tables Overview

The database consists of the following main tables:
- `users` - Stores user information
- `tasks` - Stores all tasks in the system
- `subtasks` - Stores subtasks/checklist items for each task
- `messages` - Stores communication messages between users

## Schema Details

### Users Table

The users table stores information about all system users.

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

Fields:
- `id` - Unique identifier for each user
- `full_name` - User's full name
- `email` - User's email address (used for login)
- `password` - Hashed password
- `role` - User role (admin or employee)
- `status` - User account status
- `created_at` - Timestamp when user was created

### Tasks Table

The tasks table stores all tasks in the system.

```sql
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    status ENUM('to_do', 'in_progress', 'done') DEFAULT 'to_do',
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    deadline DATE,
    created_by INT,
    assigned_to INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id)
);
```

Fields:
- `id` - Unique identifier for each task
- `title` - Task title
- `description` - Detailed description of the task
- `status` - Current status of the task: to_do, in_progress, or done
- `priority` - Task priority level
- `deadline` - Due date for the task
- `created_by` - ID of the user who created the task
- `assigned_to` - ID of the user the task is assigned to
- `created_at` - Timestamp when task was created

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

Fields:
- `id` - Unique identifier for each subtask
- `task_id` - ID of the parent task
- `title` - Subtask title
- `status` - Status of the subtask (to_do or done)

### Messages Table

The messages table stores communication between users.

```sql
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_status ENUM('read', 'unread') DEFAULT 'unread',
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
);
```

Fields:
- `id` - Unique identifier for each message
- `sender_id` - ID of the user sending the message
- `recipient_id` - ID of the user receiving the message
- `subject` - Message subject
- `message` - Message content
- `sent_at` - Timestamp when message was sent
- `read_status` - Indicates if the message has been read

## Relationships

- A **user** can create multiple tasks (one-to-many relationship from users to tasks via created_by)
- A **user** can be assigned multiple tasks (one-to-many relationship from users to tasks via assigned_to)
- A **task** can have multiple subtasks (one-to-many relationship from tasks to subtasks)
- A **user** can send multiple messages (one-to-many relationship from users to messages via sender_id)
- A **user** can receive multiple messages (one-to-many relationship from users to messages via recipient_id)

## Installation Scripts

Setup scripts are included in the project:
- `create_tables.php` - Creates all necessary tables
- `create_messages_table.php` - Creates the messages table specifically
- `check_users_table.php` - Verifies the users table structure
- `create_admin.php` - Adds an admin user
- `create_employee.php` - Adds an employee user

## Database Diagram

```
  +------------------+       +------------------+       +------------------+
  |      USERS       |       |      TASKS       |       |     SUBTASKS     |
  +------------------+       +------------------+       +------------------+
  | id (PK)          |----+  | id (PK)          |----+  | id (PK)          |
  | full_name        |    |  | title            |    |  | task_id (FK)     |
  | email            |    |  | description      |    |  | title            |
  | password         |    |  | status           |    |  | status           |
  | role             |    |  | priority         |    |  +------------------+
  | status           |    |  | deadline         |    |
  | created_at       |    |  | created_by (FK)  |<---+
  +------------------+    |  | assigned_to (FK) |<---+
                          |  | created_at       |
                          |  +------------------+
                          |
                          |  +------------------+
                          |  |     MESSAGES     |
                          |  +------------------+
                          +->| id (PK)          |
                          |  | sender_id (FK)   |
                          +->| recipient_id (FK)|
                             | subject          |
                             | message          |
                             | sent_at          |
                             | read_status      |
                             +------------------+
``` 