import Image from "next/image";

/**
 * Home Page Component
 * Version: 1.0.0
 * Last Updated: 2026-04-11
 * 
 * Displays centered application logo on dark background
 * Features:
 * - Responsive centered layout
 * - Optimized image loading with next/image
 * - Priority loading for LCP optimization
 * - Maintains aspect ratio
 */
export default function Home() {
  // Application version details displayed at bottom
  const appVersion = {
    major: 1,
    minor: 0,
    patch: 0,
    build: "2026.04.11.0839",
    environment: "production",
    timestamp: "2026-04-11T08:39:42+00:00"
  };

  return (
    // Main container: full viewport height, dark background, flex centered
    <main className="min-h-screen bg-neutral-900 flex flex-col items-center justify-center gap-8 p-6">
      
      {/* Application Logo - LCP element with priority loading */}
      <Image
        src="https://assets.kiloapps.io/user_7dfdef63-2ca5-4644-859f-5b3e1b9547b5/66cbad18-cb87-458c-8d2e-b8dc583dc5a1/e452488d-1e8f-4775-9446-45e98db06cad.png"
        alt="Telebirr Logo"
        width={240}
        height={240}
        className="object-contain drop-shadow-lg bg-white p-4 rounded-lg"
        priority // Critical for LCP performance score
        quality={90}
      />

      {/* Version Information Panel */}
      <div className="text-neutral-500 text-sm font-mono text-center space-y-1 mt-8 opacity-70">
        <div className="text-neutral-300 font-medium">
          v{appVersion.major}.{appVersion.minor}.{appVersion.patch}
        </div>
        <div className="text-xs">Build: {appVersion.build}</div>
        <div className="text-xs">{appVersion.timestamp}</div>
      </div>
    </main>
  );
}
