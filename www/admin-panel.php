<?php
session_start();
date_default_timezone_set('UTC');

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

include 'config/database.php';

// Check user permissions
if (!isset($_SESSION['user_role'])) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['user_role'] = $stmt->fetchColumn();
}
$user_role = $_SESSION['user_role'];

// Get user permissions from rank
$user_permissions = [];
try {
    $stmt = $pdo->prepare("SELECT permissions FROM user_ranks WHERE rank_name = ?");
    $stmt->execute([$user_role]);
    $rank_permissions = $stmt->fetchColumn();
    if ($rank_permissions) {
        $user_permissions = json_decode($rank_permissions, true) ?: [];
    }
} catch (Exception $e) {
    $user_permissions = [];
}

// Check if user has admin_panel permission
$has_admin_permission = ($user_role === 'super_admin') || in_array('admin_panel', $user_permissions);

if (!$has_admin_permission) {
    header('Location: dashboard.php?error=access_denied');
    exit();
}

// Handle user management actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'create_user':
            $habbo_username = $_POST['habbo_username'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role = $_POST['role'];
            
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, habbo_username) VALUES (?, ?, ?, ?)");
                $success = $stmt->execute([$habbo_username, $password, $role, $habbo_username]);
                echo json_encode(['success' => $success, 'message' => $success ? 'Usuario creado correctamente' : 'Error al crear usuario']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'update_user':
            $user_id = intval($_POST['user_id']);
            $new_role = trim($_POST['role']);
            
            // Validate role
            $valid_roles = ['usuario', 'operador', 'administrador'];
            if ($user_role === 'super_admin') {
                $valid_roles[] = 'super_admin';
            }
            
            if (!in_array($new_role, $valid_roles)) {
                echo json_encode(['success' => false, 'message' => 'Rol no válido']);
                exit();
            }
            
            // Check permissions hierarchy
            $role_hierarchy = ['usuario' => 1, 'operador' => 2, 'administrador' => 3, 'super_admin' => 4];
            $current_user_level = $role_hierarchy[$user_role] ?? 0;
            $new_role_level = $role_hierarchy[$new_role] ?? 0;
            
            if ($new_role_level >= $current_user_level && $user_role !== 'super_admin') {
                echo json_encode(['success' => false, 'message' => 'No puedes asignar un rol igual o superior al tuyo']);
                exit();
            }
            
            try {
                $pdo->beginTransaction();
                
                // Get current user info
                $stmt = $pdo->prepare("SELECT habbo_username, role FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $target_user = $stmt->fetch();
                
                if (!$target_user) {
                    echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
                    exit();
                }
                
                // Update user role
                $stmt = $pdo->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $success = $stmt->execute([$new_role, $user_id]);
                
                if ($success) {
                    // Clear any cached session data for this user by forcing a session refresh
                    // We'll create a simple session invalidation table
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS session_invalidations (
                                user_id INT PRIMARY KEY,
                                invalidated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                            )
                        ");
                        
                        $stmt = $pdo->prepare("INSERT INTO session_invalidations (user_id) VALUES (?) ON DUPLICATE KEY UPDATE invalidated_at = CURRENT_TIMESTAMP");
                        $stmt->execute([$user_id]);
                    } catch (Exception $e) {
                        // Table creation failed, continue anyway
                    }
                    
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => "Usuario '{$target_user['habbo_username']}' actualizado de '{$target_user['role']}' a '{$new_role}'. Los cambios se aplicarán en el próximo inicio de sesión.",
                        'updated_user' => $target_user['habbo_username']
                    ]);
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar el usuario']);
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
            }
            exit();
            
        case 'delete_user':
            $user_id = $_POST['user_id'];
            
            // Don't allow deleting self
            if ($user_id == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'No puedes eliminarte a ti mismo']);
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $success = $stmt->execute([$user_id]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Usuario eliminado' : 'Error al eliminar']);
            exit();
    }
}

// Get all users EXCEPT developers (hide secret role)
$stmt = $pdo->prepare("SELECT u.* FROM users u WHERE u.role NOT IN ('desarrollador', 'super_admin') ORDER BY u.role, u.username");
$stmt->execute();
$users = $stmt->fetchAll();

// Get system statistics (excluding secret developer role)
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN role = 'administrador' THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN role = 'operador' THEN 1 ELSE 0 END) as operators,
    SUM(CASE WHEN role = 'usuario' THEN 1 ELSE 0 END) as users
FROM users WHERE role != 'desarrollador'");
$stmt->execute();
$stats = $stmt->fetch();

// Function to format time
function formatTimeHMS($seconds) {
    if (!$seconds) return '00:00:00';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
}

$current_user = $_SESSION['username'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administrador - Habbo Agency</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
        }
        
        .users-table th,
        .users-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .users-table th {
            background: rgba(255, 255, 255, 0.2);
            font-weight: bold;
        }
        
        .role-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .role-desarrollador { background: rgba(236, 72, 153, 0.3); color: #ec4899; }
        .role-administrador { background: rgba(239, 68, 68, 0.3); color: #ef4444; }
        .role-operador { background: rgba(59, 130, 246, 0.3); color: #3b82f6; }
        .role-usuario { background: rgba(34, 197, 94, 0.3); color: #22c55e; }
        
        .user-form {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-edit {
            background: rgba(59, 130, 246, 0.3);
            color: #3b82f6;
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #fff;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="dashboard-title">
                    <i class="fas fa-users-cog"></i>
                    Panel de Administrador
                </h1>
                
                <div class="header-actions">
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                        Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="dashboard-card">
            <h3 class="card-title">
                <i class="fas fa-chart-pie"></i>
                Estadísticas de Usuarios
            </h3>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                    <div>Total Usuarios</div>
                </div>

                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['admins']; ?></div>
                    <div>Administradores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['operators']; ?></div>
                    <div>Operadores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['users']; ?></div>
                    <div>Usuarios</div>
                </div>
            </div>
        </div>

        <!-- Users Management -->
        <div class="dashboard-card" style="margin-top: 30px;">
            <h3 class="card-title">
                <i class="fas fa-users"></i>
                Gestión de Usuarios
            </h3>
            
            <div style="overflow-x: auto;">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre de Habbo</th>
                            <th>Rol</th>
                            <th>Creado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($user['profile_image'] && file_exists($user['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                             style="width: 30px; height: 30px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, rgb(60, 30, 80), rgb(40, 60, 30)); display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold;">
                                            <?php echo strtoupper(substr($user['habbo_username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($user['habbo_username']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-small btn-edit" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>')">
                                        <i class="fas fa-edit"></i>
                                        Editar
                                    </button>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <button class="btn-small btn-delete" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
                                        <i class="fas fa-trash"></i>
                                        Eliminar
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3>Editar Usuario</h3>
            <form id="editUserForm">
                <div class="form-group">
                    <label for="edit_role">Rol:</label>
                    <select id="edit_role" name="role" class="glass-input" required>
                        <option value="usuario">Usuario</option>
                        <option value="operador">Operador</option>
                        <option value="administrador">Administrador</option>
                        <?php if ($user_role === 'desarrollador'): ?>
                        <option value="desarrollador">Desarrollador</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="glass-button">
                        <i class="fas fa-save"></i>
                        Guardar
                    </button>
                    <button type="button" class="glass-button" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let editingUserId = null;

        // Create user form
        document.getElementById('createUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'create_user');
            
            try {
                const response = await fetch('admin-panel.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Usuario creado correctamente');
                    location.reload();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('Error de conexión');
            }
        });

        // Edit user
        function editUser(userId, currentRole) {
            editingUserId = userId;
            document.getElementById('edit_role').value = currentRole;
            document.getElementById('editModal').style.display = 'block';
        }

        // Close modal
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
            editingUserId = null;
        }

        // Edit user form
        document.getElementById('editUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'update_user');
            formData.append('user_id', editingUserId);
            formData.append('role', document.getElementById('edit_role').value);
            
            try {
                const response = await fetch('admin-panel.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(result.message || 'Usuario actualizado correctamente');
                    closeModal();
                    location.reload();
                } else {
                    alert(result.message || 'Error al actualizar usuario');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error de conexión');
            }
        });

        // Delete user
        async function deleteUser(userId, username) {
            if (!confirm(`¿Estás seguro de que quieres eliminar al usuario "${username}"?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);
            
            try {
                const response = await fetch('admin-panel.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Usuario eliminado correctamente');
                    location.reload();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('Error de conexión');
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>