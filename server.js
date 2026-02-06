const express = require("express");
const fs = require("fs");
const path = require("path");

const app = express();
const PORT = 80;
const DATA_FILE = path.join(__dirname, "projects.json");
const PROJECT_DIR = path.join(__dirname, "project");

// Ensure project directory exists
if (!fs.existsSync(PROJECT_DIR)) {
    fs.mkdirSync(PROJECT_DIR, { recursive: true });
}

// Middleware
app.use(express.json());
app.use(express.static(path.join(__dirname)));

// Helper to get individual project filename
function getProjectFilename(project) {
    const safeTitle = project.title
        .replace(/[^a-zA-Z0-9]/g, "_")
        .replace(/_+/g, "_")
        .substring(0, 50);
    
    return `${project.id}_${safeTitle}.json`;
}

// Helper to save individual project file
function saveIndividualProject(project) {
    try {
        const filename = getProjectFilename(project);
        const filePath = path.join(PROJECT_DIR, filename);
        
        fs.writeFileSync(filePath, JSON.stringify(project, null, 2));
        console.log(`✓ Saved individual project: ${filename}`);
    } catch (err) {
        console.error("Error saving individual project:", err);
    }
}

// Helper to delete individual project file by ID
function deleteIndividualProjectById(projectId) {
    try {
        // Look for files with this ID
        const files = fs.readdirSync(PROJECT_DIR)
            .filter(f => f.endsWith('.json'));
        
        for (const file of files) {
            // Extract ID from filename (format: ID_Title.json)
            const match = file.match(/^(\d+)_/);
            if (match && parseInt(match[1]) === projectId) {
                const filePath = path.join(PROJECT_DIR, file);
                fs.unlinkSync(filePath);
                console.log(`✓ Deleted individual project file: ${file}`);
                return true;
            }
        }
        console.log(`No individual file found for project ID: ${projectId}`);
        return false;
    } catch (err) {
        console.error("Error deleting project file:", err);
        return false;
    }
}

// Load projects from the JSON file
app.get("/api/projects", (req, res) => {
    if (fs.existsSync(DATA_FILE)) {
        res.json(JSON.parse(fs.readFileSync(DATA_FILE, "utf-8")));
    } else {
        res.json([]);
    }
});

// Save projects to the JSON file AND handle deletions
app.post("/api/projects", (req, res) => {
    const newProjects = req.body;
    
    // Get current projects before saving
    let oldProjects = [];
    if (fs.existsSync(DATA_FILE)) {
        oldProjects = JSON.parse(fs.readFileSync(DATA_FILE, "utf-8"));
    }
    
    // Find projects that were deleted (in old but not in new)
    const deletedProjects = oldProjects.filter(oldProject => 
        !newProjects.find(p => p.id === oldProject.id)
    );
    
    // Delete individual files for removed projects
    deletedProjects.forEach(project => {
        deleteIndividualProjectById(project.id);
    });
    
    // Save main projects file
    fs.writeFileSync(DATA_FILE, JSON.stringify(newProjects, null, 2));
    
    // Also save each remaining project individually
    newProjects.forEach(project => {
        saveIndividualProject(project);
    });
    
    console.log(`Saved ${newProjects.length} projects, deleted ${deletedProjects.length} projects`);
    res.json({ status: "ok" });
});

// Optional: Direct delete endpoint
app.delete("/api/project/:id", (req, res) => {
    const projectId = parseInt(req.params.id);
    
    if (deleteIndividualProjectById(projectId)) {
        res.json({ status: "ok", message: "Project file deleted" });
    } else {
        res.status(404).json({ error: "Project file not found" });
    }
});

// Clean up orphaned files (optional endpoint)
app.post("/api/projects/cleanup", (req, res) => {
    try {
        let existingProjects = [];
        if (fs.existsSync(DATA_FILE)) {
            existingProjects = JSON.parse(fs.readFileSync(DATA_FILE, "utf-8"));
        }
        
        const existingIds = existingProjects.map(p => p.id);
        const files = fs.readdirSync(PROJECT_DIR)
            .filter(f => f.endsWith('.json'));
        
        let deletedCount = 0;
        files.forEach(file => {
            const match = file.match(/^(\d+)_/);
            if (match) {
                const fileId = parseInt(match[1]);
                if (!existingIds.includes(fileId)) {
                    const filePath = path.join(PROJECT_DIR, file);
                    fs.unlinkSync(filePath);
                    console.log(`Cleaned up orphaned file: ${file}`);
                    deletedCount++;
                }
            }
        });
        
        res.json({ status: "ok", deleted: deletedCount });
    } catch (err) {
        console.error("Error during cleanup:", err);
        res.status(500).json({ error: "Cleanup failed" });
    }
});

// Start the server
app.listen(PORT, () => {
    console.log(`Server running on http://localhost:${PORT}`);
    console.log(`Individual project files stored in: ${PROJECT_DIR}`);
    
    // Perform initial cleanup on startup
    try {
        let existingProjects = [];
        if (fs.existsSync(DATA_FILE)) {
            existingProjects = JSON.parse(fs.readFileSync(DATA_FILE, "utf-8"));
        }
        
        const existingIds = existingProjects.map(p => p.id);
        const files = fs.readdirSync(PROJECT_DIR)
            .filter(f => f.endsWith('.json'));
        
        let orphanedCount = 0;
        files.forEach(file => {
            const match = file.match(/^(\d+)_/);
            if (match) {
                const fileId = parseInt(match[1]);
                if (!existingIds.includes(fileId)) {
                    orphanedCount++;
                }
            }
        });
        
        if (orphanedCount > 0) {
            console.log(`Found ${orphanedCount} orphaned project files. Run POST /api/projects/cleanup to remove them.`);
        }
    } catch (err) {
        console.error("Error checking for orphaned files:", err);
    }
});