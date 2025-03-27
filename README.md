# Task Management System (TSM)

A comprehensive task and project management system built with PHP and MySQL. TSM helps teams organize, track, and collaborate on tasks efficiently.

## Features

- **User Management**: Admin and employee roles with different permissions
- **Task Management**: Create, assign, update, and track tasks
- **Subtasks**: Break down larger tasks into manageable subtasks
- **Status Tracking**: Track task progress (To Do, In Progress, Done)
- **Priority Levels**: Assign priority levels (High, Medium, Low)
- **Messaging System**: Internal communication between team members
- **Dashboard**: Visual overview of task status and progress
- **Responsive Design**: Modern dark theme UI that works on any device

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache recommended)

## Installation

1. Clone the repository:
   ```
   git clone https://github.com/IAMOGTABA/TSM-GR.git
   ```

2. Import the database structure:
   - Create a MySQL database named `tsm`
   - Set up your database connection in `config.php`
   - Run the installation scripts:
     - `create_tables.php`
     - `create_messages_table.php`

3. Create admin user:
   - Run `create_admin.php` to set up an admin account

4. Access the system:
   - Navigate to `login.php` in your browser
   - Log in with the admin credentials

## Database Structure

See [DATABASE.md](DATABASE.md) for complete database schema details.

## User Roles

- **Admin**: Can manage users, create/edit/delete tasks, assign tasks, access all system features
- **Employee**: Can view and update assigned tasks, create subtasks, use messaging system

## Screenshots

(Screenshots will be added soon)

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Author

IAMOGTABA 