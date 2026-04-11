import Image from "next/image";

export default function Home() {
  return (
    <main className="min-h-screen bg-neutral-900 flex items-center justify-center">
      <Image
        src="https://assets.kiloapps.io/user_7dfdef63-2ca5-4644-859f-5b3e1b9547b5/66cbad18-cb87-458c-8d2e-b8dc583dc5a1/204d3e2c-eaf3-4463-9a26-c66840e09b01.png"
        alt="Logo"
        width={200}
        height={200}
        className="object-contain"
        priority
      />
    </main>
  );
}
