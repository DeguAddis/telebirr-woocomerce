import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

/**
 * Root Layout Component
 * Version: 1.0.0
 * Last Updated: 2026-04-11
 * 
 * Applies global font configuration and base styles
 * Wraps all application pages
 * 
 * Font Configuration:
 * - Geist Sans: Primary sans-serif font
 * - Geist Mono: Monospace font for code/technical text
 * - Both with latin subset for optimal loading
 */

// Primary Sans-serif font configuration
const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
  display: "swap", // Prevents FOIT
});

// Monospace font configuration for technical content
const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
  display: "swap",
});

// Application metadata - used for SEO and browser tab info
export const metadata: Metadata = {
  title: "Next.js Template",
  description: "A minimal Next.js starter template",
  applicationName: "Next.js Starter",
};

/**
 * Root Layout Wrapper
 * @param children - Page components to render inside layout
 * @returns Full HTML document structure
 */
export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className="h-full">
      {/* Body with font variables and antialiased text rendering */}
      <body
        className={`${geistSans.variable} ${geistMono.variable} antialiased min-h-full`}
      >
        {children}
      </body>
    </html>
  );
}