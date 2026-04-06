<!DOCTYPE html>
<html>
<head><title>NAS Server</title></head>
<body>
    <h1>NAS Web Server is Running</h1>
    <h2>Environment Check:</h2>
    <ul>
        <li>PHP Version: <?php echo phpversion(); ?></li>
        <li>Apache: Running</li>
        <li>MySQL Connection:
        <?php
        $conn = new mysqli('db', getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'), getenv('MYSQL_DATABASE'));
        if ($conn->connect_error) {
            echo '<span style="color:red">Failed - ' . htmlspecialchars($conn->connect_error) . '</span>';
        } else {
            echo '<span style="color:green">Connected successfully</span>';
            $conn->close();
        }
        ?>
        </li>
    </ul>
</body>
</html>
