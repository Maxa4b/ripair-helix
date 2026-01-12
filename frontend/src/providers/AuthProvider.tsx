import { useCallback, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import apiClient from "../api/client";
import { AuthContext } from "./authContext";

type HelixUser = {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  role: "owner" | "manager" | "technician" | "frontdesk";
  phone: string | null;
  color: string | null;
  is_active: boolean;
};

type Credentials = {
  email: string;
  password: string;
};

export type AuthContextValue = {
  user: HelixUser | undefined;
  token: string | null;
  isLoadingUser: boolean;
  login: (credentials: Credentials) => Promise<void>;
  logout: () => Promise<void>;
};

type Props = {
  children: ReactNode;
};

export function AuthProvider({ children }: Props) {
  const queryClient = useQueryClient();
  const [token, setToken] = useState<string | null>(() => {
    const stored = localStorage.getItem("helixToken");
    if (!stored || stored === "undefined" || stored === "null") {
      localStorage.removeItem("helixToken");
      return null;
    }
    return stored;
  });

  useEffect(() => {
    if (token) {
      apiClient.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
      delete apiClient.defaults.headers.common.Authorization;
    }
  }, [token]);

  const userQuery = useQuery<HelixUser>({
    queryKey: ["auth", "me"],
    queryFn: async () => {
      const response = await apiClient.get("/auth/me");
      return response.data.data;
    },
    enabled: Boolean(token),
    retry: false,
  });

  useEffect(() => {
    if (userQuery.isError) {
      localStorage.removeItem("helixToken");
      setToken(null);
    }
  }, [userQuery.isError]);

  const loginMutation = useMutation({
    mutationFn: async (credentials: Credentials) => {
      const response = await apiClient.post("/auth/login", credentials);
      const data = response.data as unknown;
      if (!data || typeof data !== "object") {
        throw new Error("Réponse API invalide (login).");
      }
      const tokenValue = (data as { token?: unknown }).token;
      if (typeof tokenValue !== "string" || tokenValue.trim() === "") {
        throw new Error("Connexion impossible: token manquant (API).");
      }
      return data as { token: string; user: HelixUser };
    },
    onSuccess: ({ token: newToken }) => {
      localStorage.setItem("helixToken", newToken);
      setToken(newToken);
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
    },
  });

  const logoutMutation = useMutation({
    mutationFn: async () => {
      if (token) {
        await apiClient.post("/auth/logout");
      }
    },
    onSuccess: () => {
      localStorage.removeItem("helixToken");
      setToken(null);
      queryClient.removeQueries({ queryKey: ["auth"] });
    },
  });

  const login = useCallback(
    async (credentials: Credentials) => {
      await loginMutation.mutateAsync(credentials);
    },
    [loginMutation],
  );

  const logout = useCallback(async () => {
    await logoutMutation.mutateAsync();
  }, [logoutMutation]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user: userQuery.data,
      token,
      isLoadingUser: userQuery.isPending,
      login,
      logout,
    }),
    [login, logout, token, userQuery.data, userQuery.isPending],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
