# TSM-GR Installation Guide

## Quick Start (Recommended)

### Prerequisites
1. **XAMPP** installed on your system
   - Download from: https://www.apachefriends.org/
   - Install with default settings

### Installation Steps

1. **Start XAMPP Services**
   - Open XAMPP Control Panel
   - Start **Apache** service
   - Start **MySQL** service
   - Both should show green "Running" status

2. **Access the Application**
   - Open your web browser
   - Navigate to: `http://localhost/TSM-GR/`
   - The system will automatically guide you through setup

3. **Automatic Setup**
   - The system will detect if setup is needed
   - Follow the on-screen instructions
   - Database and tables will be created automatically
   - Default admin user will be created

4. **Login**
   - After setup completes, you'll be redirected to login
   - Use the provided admin credentials:
     - **Email**: `admin@test.com`
     - **Password**: `admin123`

---

## Manual Installation (Advanced)

If you prefer manual setup or encounter issues:

### Step 1: Database Setup
```sql
-- Create database
CREATE DATABASE task_management;
USE task_management;
```

### Step 2: Run Setup Scripts (in order)
1. Visit: `http://localhost/TSM-GR/setup.php`
2. Or manually run these files in order:
   - `check_users_table.php` (optional - for verification)
   - `create_tables.php`
   - `create_messages_table.php`
   - `create_admin.php`

### Step 3: Access Application
- Navigate to: `http://localhost/TSM-GR/login.php`

---

## Default User Accounts

### Admin Account
- **Email**: `admin@test.com`
- **Password**: `admin123`
- **Role**: Administrator
- **Permissions**: Full system access

### Employee Account (Sample)
- **Email**: `employee@test.com`
- **Password**: `employee123`
- **Role**: Employee
- **Permissions**: Limited access

---

## Configuration

### Database Settings
File: `config.php`
```php
$host = 'localhost';
$db   = 'task_management';
$user = 'root';
$pass = '';  // Default XAMPP password is empty
```

### XAMPP Default Settings
- **MySQL Host**: `localhost`
- **MySQL Port**: `3306`
- **MySQL User**: `root`
- **MySQL Password**: (empty)
- **Apache Port**: `80`

---

## Troubleshooting

### Common Issues

#### 1. "XAMPP MySQL service is not running"
**Solution:**
- Open XAMPP Control Panel
- Click "Start" next to MySQL
- If it fails to start, check if port 3306 is in use
- Try stopping other MySQL services

#### 2. "Database connection failed"
**Solution:**
- Verify XAMPP MySQL is running
- Check `config.php` database credentials
- Ensure database name is correct

#### 3. "Access denied for user 'root'"
**Solution:**
- Reset MySQL root password in XAMPP
- Or update `config.php` with correct credentials

#### 4. "Table doesn't exist" errors
**Solution:**
- Run the setup process again: `http://localhost/TSM-GR/setup.php`
- Or manually run `create_tables.php`

#### 5. "Permission denied" on Windows
**Solution:**
- Run XAMPP as Administrator
- Check folder permissions for `C:\xampp\htdocs\TSM-GR`

### Port Conflicts
If default ports are in use:

#### Apache (Port 80)
- Change in XAMPP Config → Apache → Config → httpd.conf
- Find `Listen 80` and change to `Listen 8080`
- Access via: `http://localhost:8080/TSM-GR/`

#### MySQL (Port 3306)
- Change in XAMPP Config → MySQL → Config → my.ini
- Find `port=3306` and change to desired port
- Update `config.php` accordingly

---

## File Structure

```
TSM-GR/
├── index.php              # Entry point (auto-redirects)
├── setup.php              # Automatic setup script
├── config.php             # Database configuration
├── login.php              # Login page
├── admin-dashboard.php    # Admin dashboard
├── employee-dashboard.php # Employee dashboard
├── create_tables.php      # Manual table creation
├── create_admin.php       # Manual admin creation
└── ...                    # Other application files
```

---

## Security Notes

### Important: Change Default Passwords
After installation, immediately:

1. **Change Admin Password**
   - Login as admin
   - Go to Profile/Settings
   - Update password

2. **Remove/Disable Test Accounts**
   - Delete or deactivate sample employee account
   - Create real user accounts

3. **Secure Database**
   - Set MySQL root password
   - Update `config.php` accordingly
   - Consider creating dedicated database user

### Production Deployment
For production use:
- Use strong passwords
- Enable HTTPS
- Set proper file permissions
- Regular database backups
- Update PHP and MySQL regularly

---

## Support

### Getting Help
1. Check this installation guide
2. Review error messages carefully
3. Verify XAMPP services are running
4. Check PHP error logs in XAMPP

### System Requirements
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Web Server**: Apache (included with XAMPP)
- **Browser**: Modern browser with JavaScript enabled

---

## Next Steps

After successful installation:

1. **Login as Admin**
   - Use provided credentials
   - Explore the admin dashboard

2. **Create Users**
   - Add real employee accounts
   - Set appropriate roles and permissions

3. **Start Using**
   - Create your first task
   - Assign tasks to users
   - Explore all features

4. **Customize**
   - Update system settings
   - Modify user roles as needed
   - Set up regular backups

---

**Congratulations! Your TSM-GR Task Management System is now ready to use!**
